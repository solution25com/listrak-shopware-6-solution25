<?php

declare(strict_types=1);

namespace Listrak\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Library\Constants\Endpoints;
use Psr\Log\LoggerInterface;
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
    public const EMAIL_INTEGRATION = 'EMAIL';
    public const DATA_INTEGRATION = 'DATA';
    private const TOKEN_URL = 'https://auth.listrak.com/OAuth2/Token';

    private LoggerInterface $logger;

    private Client $client;

    private EntityRepository $failedRequestRepository;

    private ?string $dataAccessToken = null;

    private ?int $dataAccessTokenExpiry = null;

    private ?string $emailAccessToken = null;

    private ?int $emailAccessTokenExpiry = null;

    private ListrakConfigService $listrakConfigService;

    private ?FailedRequestEntity $failedRequestEntity;

    public function __construct(
        ListrakConfigService $listrakConfig,
        EntityRepository $failedRequestRepository,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfig;
        $this->failedRequestRepository = $failedRequestRepository;
        $this->emailAccessToken = $this->listrakConfigService->getConfig('emailToken');
        $this->emailAccessTokenExpiry = $this->listrakConfigService->getConfig('emailTokenExpiry');
        $this->dataAccessToken = $this->listrakConfigService->getConfig('dataToken');
        $this->dataAccessTokenExpiry = $this->listrakConfigService->getConfig('dataTokenExpiry');
        $this->logger = $logger;
        $this->client = new Client();
    }

    public function getAccessToken(string $type): string
    {
        if ($type === self::DATA_INTEGRATION && $this->dataAccessToken && $this->dataAccessTokenExpiry > time()) {
            return $this->dataAccessToken;
        }
        if ($type === self::EMAIL_INTEGRATION && $this->emailAccessToken && $this->emailAccessTokenExpiry > time()) {
            return $this->emailAccessToken;
        }

        $body = $this->buildAuthRequestBody($type);
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
            if ($type === self::DATA_INTEGRATION) {
                $this->dataAccessToken = $data['access_token'] ?? 'Unknown error';
                $this->dataAccessTokenExpiry = time() + 3599;
                $this->listrakConfigService->setConfig('dataToken', $this->dataAccessToken);
                $this->listrakConfigService->setConfig('dataTokenExpiry', $this->dataAccessTokenExpiry);

                return $this->dataAccessToken;
            }
            $this->emailAccessToken = $data['access_token'] ?? 'Unknown error';
            $this->emailAccessTokenExpiry = time() + 3599;
            $this->listrakConfigService->setConfig('emailToken', $this->emailAccessToken);
            $this->listrakConfigService->setConfig('emailTokenExpiry', $this->emailAccessTokenExpiry);

            return $this->emailAccessToken;
        }
        $this->logger->error('Unable to retrieve access token');

        return 'Error: Invalid response received';
    }

    /**
     * @return array<string,string>
     */
    public function buildAuthRequestBody(string $type): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $type === self::DATA_INTEGRATION ? $this->listrakConfigService->getConfig('dataClientId') : $this->listrakConfigService->getConfig('emailClientId'),
            'client_secret' => $type === self::DATA_INTEGRATION ? $this->listrakConfigService->getConfig('dataClientSecret') : $this->listrakConfigService->getConfig('emailClientSecret'),
        ];
    }

    /**
     * @param array<string,array<string, string|null>|string> $data
     */
    public function importCustomer(array $data, Context $context): void
    {
        $fullEndpointUrl = Endpoints::getUrl(Endpoints::CUSTOMER_IMPORT);
        $this->logger->debug('Importing customer', ['data' => $data]);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(self::DATA_INTEGRATION),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
        ];

        $this->request($fullEndpointUrl, $options, $context);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function importOrder(array $data, Context $context): void
    {
        $fullEndpointUrl = Endpoints::getUrl(Endpoints::ORDER_IMPORT);
        $this->logger->debug('Importing order', ['data' => $data]);
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(self::DATA_INTEGRATION),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
        ];

        $this->request($fullEndpointUrl, $options, $context);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createOrUpdateContact(array $data, Context $context): void
    {
        $listId = $this->listrakConfigService->getConfig('listId');
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(Endpoints::CONTACT_CREATE, [$listId, 'Contact']);
            $this->logger->debug('Creating contact', ['data' => $data]);
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(self::EMAIL_INTEGRATION),
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data),
            ];

            $this->request($fullEndpointUrl, $options, $context);
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
     * @param array<string,string[]> $options
     */
    public function saveRequestToFailedRequests(
        string $endpoint,
        string $method,
        array $options,
        string $response,
        ?Context $context = null
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
        }
    }

    public function removeFromFailedRequests(?Context $context): void
    {
        if ($context && $this->failedRequestEntity) {
            $this->failedRequestRepository->delete([
                ['id' => $this->failedRequestEntity->getId()],
            ], $context);
        }
    }

    /**
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
     */
    private function request(
        array $endpoint,
        array $options,
        ?Context $context = null,
        ?FailedRequestEntity $failedRequestEntity = null
    ): ?string {
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
        $this->logger->error(
            'Data sync failed: ' . $e->getMessage()
        );
    }

    /**
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
