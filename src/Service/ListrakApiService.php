<?php

declare(strict_types=1);

namespace Listrak\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Library\Constants\Endpoints;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class ListrakApiService extends Endpoints
{
    public const MAX_RETRY_COUNT = 3;
    private const TOKEN_URL = 'https://auth.listrak.com/OAuth2/Token';

    private LoggerInterface $logger;

    private Client $client;

    private EntityRepository $failedRequestRepository;

    private ?string $accessToken = null;

    private ListrakConfigService $listrakConfigService;

    private ?FailedRequestEntity $failedRequestEntity;

    public function __construct(
        ListrakConfigService $listrakConfig,
        LoggerInterface $logger,
        EntityRepository $failedRequestRepository,
    ) {
        $this->listrakConfigService = $listrakConfig;
        $this->logger = $logger;
        $this->failedRequestRepository = $failedRequestRepository;
        $this->client = new Client();
    }

    public function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $body = $this->buildAuthRequestBody();

        $options = [
            'form_params' => $body,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ];
        $responseContent = $this->request(['method' => 'POST', 'url' => self::TOKEN_URL], $options);

        if ($responseContent) {
            $data = json_decode($responseContent, true);
            if (isset($data['error']) && $data['error']) {
                return 'Error: ' . ($data['message'] ?? 'Unknown error');
            }

            $this->accessToken = $data['access_token'] ?? 'Unknown error';

            return $this->accessToken;
        }

        return 'Error: Invalid response received';
    }

    /**
     * @return array<string,string>
     */
    public function buildAuthRequestBody(): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $this->listrakConfigService->getConfig('clientId'),
            'client_secret' => $this->listrakConfigService->getConfig('clientSecret'),
        ];
    }

    /**
     * @param array<string,array<string, string|null>|string> $data
     * @param Context $context
     */
    public function importCustomer(array $data, Context $context): void
    {
        $fullEndpointUrl = Endpoints::getUrl(Endpoints::CUSTOMER_IMPORT);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([$data]),
        ];

        $this->request($fullEndpointUrl, $options, $context);
    }

    /**
     * @param array<string, mixed> $data
     * @param Context $context
     */
    public function importOrder(array $data, Context $context): void
    {
        $fullEndpointUrl = Endpoints::getUrl(Endpoints::ORDER_IMPORT);
        $this->logger->debug('Importing order', ['data' => $data]);
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([$data]),
        ];

        $this->request($fullEndpointUrl, $options, $context);
    }

    /**
     * @param string $status
     * @return string
     */
    public function mapOrderStatus(string $status): string
    {
        $sw_order_states = [
            'open' => 'Pending',
            'in_progress' => 'Processing',
            'completed' => 'Completed',
            'cancelled' => 'Canceled',
        ];
        if (array_key_exists($status, $sw_order_states)) {
            return $sw_order_states[$status];
        }

        return 'Unknown';
    }

    /**
     * @param OrderLineItemEntity $lineItem
     * @return string
     */
    public function generateSku(OrderLineItemEntity $lineItem): string
    {
        switch ($lineItem->getType()) {
            case LineItem::PRODUCT_LINE_ITEM_TYPE:
                return $lineItem->getPayload()['productNumber'] ?? 'PRODUCT_[' . $lineItem->getIdentifier() . ']';
            case LineItem::CONTAINER_LINE_ITEM:
                return 'CONTAINER_ITEM_[' . $lineItem->getIdentifier() . ']';
            case LineItem::DISCOUNT_LINE_ITEM:
                return 'DISCOUNT_ITEM_[' . $lineItem->getIdentifier() . ']';
            case LineItem::PROMOTION_LINE_ITEM_TYPE:
                return 'PROMOTION_ITEM_[' . $lineItem->getIdentifier() . ']';
            case LineItem::CREDIT_LINE_ITEM_TYPE:
                return 'CREDIT_LINE_ITEM_[' . $lineItem->getIdentifier() . ']';
            default:
                return 'CUSTOM_ITEM_[' . $lineItem->getIdentifier() . ']';
        }
    }

    public function handleResponse(mixed $response): void
    {
        if (isset($response['status']) && $response['status'] === '201') {
            $this->logger->debug(
                'Data synced with Listrak successfully.',
                ['resourceId' => $response['resourceId']]
            );
        }
        if (isset($response['error'])) {
            $this->logger->error(
                'Data sync failed with error: ' . $response['message']
            );
        }
    }

    /**
     * @param string $endpoint
     * @param string $method
     * @param array<string,string[]> $options
     * @param string $response
     * @param Context|null $context
     * @return void
     */
    public function saveRequestToFailedRequests(
        string $endpoint,
        string $method,
        array $options,
        string $response,
        Context $context = null
    ): void {
        if ($context !== null) {
            if ($this->failedRequestEntity) {
                $this->failedRequestEntity->setLastRetryAt(new \DateTime('now'));
                $this->failedRequestEntity->setRetryCount($this->failedRequestEntity->getRetryCount() + 1);
                $this->failedRequestEntity->setResponse($response);
            } else {
                $this->failedRequestEntity = new FailedRequestEntity();
                $this->failedRequestEntity->setId(Uuid::randomHex());
                $this->failedRequestEntity->setResponse($response);
                $this->failedRequestEntity->setMethod($method);
                $this->failedRequestEntity->setEndpoint($endpoint);

            }
            $this->failedRequestEntity->setOptions($options);
            $this->failedRequestRepository->upsert([
                [
                    'id' => $this->failedRequestEntity->getId(),
                    'retryCount' => $this->failedRequestEntity->getRetryCount(),
                    'lastRetryAt' => $this->failedRequestEntity->getLastRetryAt(),
                    'method' => $this->failedRequestEntity->getMethod(),
                    'endpoint' => $this->failedRequestEntity->getEndpoint(),
                    'response' => $this->failedRequestEntity->getResponse(),
                    'options' => $this->failedRequestEntity->getOptions(),
                ],
            ], $context);
            $res = $this->failedRequestRepository->search(new Criteria([$this->failedRequestEntity->getId()]),
                $context);
            $this->logger->debug('Retrieved from failed requests', ['rs' => $res->first()]);
        }
    }


    /**
     * @param Context|null $context
     * @return void
     */
    public function removeFromFailedRequests(?Context $context): void
    {
        if ($context && $this->failedRequestEntity) {
            $this->failedRequestRepository->delete([
                ['id' => $this->failedRequestEntity->getId()],
            ], $context);
        }
    }

    /**
     * @param Context $context
     * @return void
     * @throws DataAbstractionLayerException
     */
    public function retry(Context $context): void
    {
        if ($this->listrakConfigService->getConfig('enableOrderSync') | $this->listrakConfigService->getConfig('enableCustomerSync')) {
            foreach ($this->findEntries($context) as $failedRequestEntry) {
                $this->request(
                    ['url' => $failedRequestEntry->getEndpoint(), 'method' => $failedRequestEntry->getMethod()],
                    $failedRequestEntry->getOptions(),
                    $context,
                    $failedRequestEntry
                );
            }
        }
    }

    /**
     * @param array<string,string> $endpoint
     * @param array<string,mixed> $options
     * @param Context|null $context
     * @param FailedRequestEntity|null $failedRequestEntity
     * @return string|null
     */
    private function request(
        array $endpoint,
        array $options,
        Context $context = null,
        FailedRequestEntity $failedRequestEntity = null
    ): string|null {
        $responseContent = null;
        $this->failedRequestEntity = $failedRequestEntity;
        try {
            ['method' => $method, 'url' => $url] = $endpoint;
            $response = $this->client->request($method, $url, $options);
            $responseContent = $response->getBody()->getContents();
            $decodedResponse = json_decode($responseContent, true);
            if (isset($decodedResponse['error'])) {
                $this->saveRequestToFailedRequests($url, $method, $options, $responseContent, $context);
            } else {
                $this->removeFromFailedRequests($context);
            }
            $this->handleResponse($decodedResponse);
        } catch (GuzzleException $e) {
            $this->saveRequestToFailedRequests($url, $method, $options, $e->getMessage(), $context);
            $this->handleError($e);
        }

        return $responseContent;
    }

    private function handleError(GuzzleException $e): void
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse() ?? null;
            if ($response) {
                $responseBody = $response->getBody()->getContents();
                $decodedBody = json_decode($responseBody, true);
                $this->logger->error(
                    'Data sync failed with error: ' . $decodedBody['message']
                );
            }
        } else {
            $this->logger->error(
                'Data sync failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param Context $context
     * @return EntityCollection
     * @throws DataAbstractionLayerException
     */
    private function findEntries(Context $context): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('retryCount', [
            RangeFilter::LT => self::MAX_RETRY_COUNT,
        ]));

        return $this->failedRequestRepository->search($criteria, $context)->getEntities();
    }
}
