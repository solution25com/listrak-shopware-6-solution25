<?php

declare(strict_types=1);

namespace Listrak\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Library\Endpoints;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

class ListrakApiService extends Endpoints
{
    public const EMAIL_INTEGRATION = 'EMAIL';
    public const DATA_INTEGRATION = 'DATA';
    public const TOKEN_URL = 'https://auth.listrak.com/OAuth2/Token';

    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly FailedRequestService $failedRequestService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<array> $data
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
        $this->failedRequestService->flushFailedRequests($context);
    }

    /**
     * @param list<array> $data
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
        $this->failedRequestService->flushFailedRequests($context);
    }

    public function createOrUpdateContact(array $data, Context $context): void
    {
        $listId = $this->listrakConfigService->getConfig('listId');
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(Endpoints::CONTACT_CREATE, [$listId, 'Contact'], ['overrideUnsubscribe' => 'true']);
            $this->logger->debug('Creating contact', ['data' => $data]);
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(self::EMAIL_INTEGRATION),
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data),
            ];

            $this->request($fullEndpointUrl, $options, $context);
            $this->failedRequestService->flushFailedRequests($context);
        }
    }

    public function startListImport(array $data, Context $context): void
    {
        $listId = trim($this->listrakConfigService->getConfig('listId'));
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(Endpoints::START_LIST_IMPORT, [$listId, 'ListImport']);
            $this->logger->debug('Creating list import', ['data' => $data]);
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(self::EMAIL_INTEGRATION),
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data),
            ];

            $this->request($fullEndpointUrl, $options, $context);
            $this->failedRequestService->flushFailedRequests($context);
        }
    }

    public function sendTransactionalMessage($transactionalMessageId, array $data, Context $context): void
    {
        $listId = trim($this->listrakConfigService->getConfig('transactionalListId'));
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(Endpoints::START_LIST_IMPORT, [$listId, 'TransactionalMessage',$transactionalMessageId,'Message']);
            $this->logger->debug('Sending transactional message', ['data' => $data]);
            foreach($data as $message) {
                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getAccessToken(self::EMAIL_INTEGRATION),
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($message),
                ];

                $this->request($fullEndpointUrl, $options, $context);
            }
            $this->failedRequestService->flushFailedRequests($context);
        }
    }

    public function handleResponse(mixed $response): void
    {
        if (isset($response['status']) && ($response['status'] === '201' || $response['status'] === '200' || $response['status'] === 200 || $response['status'] === 201)) {
            if (\array_key_exists('resourceId', $response)) {
                $this->logger->debug(
                    'Data synced with Listrak successfully.',
                    ['resourceId' => $response['resourceId'] ?? null]
                );
            }

            if (isset($response['data'])) {
                $this->logger->debug(
                    'Data synced with Listrak successfully.',
                    ['data' => $response['data']]
                );
            }
        }
        if (isset($response['error'])) {
            $this->logger->error(
                'Data sync failed with error: ' . $response['message']
            );
        }
    }

    /**
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $options
     */
    public function request(
        array $endpoint,
        array $options,
        ?Context $context = null,
        ?FailedRequestEntity $failedRequestEntity = null
    ): ?string {
        $responseContent = null;

        try {
            ['method' => $method, 'url' => $url] = $endpoint;
            $client = new Client();
            $response = $client->request($method, $url, $options);
            $responseContent = $response->getBody()->getContents();

            $decodedResponse = json_decode($responseContent, true);

            if (isset($decodedResponse['error'])) {
                if ($failedRequestEntity) {
                    $failedRequestEntity->setResponse($responseContent);
                    $this->failedRequestService->updateFailedRequest($failedRequestEntity);
                } else {
                    $this->failedRequestService->saveRequestToFailedRequests($url, $method, $options, $responseContent, $context);
                }
            } else {
                $this->failedRequestService->removeFromFailedRequests($context, $failedRequestEntity);
            }

            $this->handleResponse($decodedResponse);
        } catch (GuzzleException $e) {
            if ($failedRequestEntity) {
                $failedRequestEntity->setResponse($e->getMessage());
                $this->failedRequestService->updateFailedRequest($failedRequestEntity);
            } else {
                $this->failedRequestService->saveRequestToFailedRequests($url, $method, $options, $e->getMessage(), $context);
            }

            $this->handleError($e);
        }

        return $responseContent;
    }

    public function getAccessToken(string $type): string
    {
        if ($type === self::DATA_INTEGRATION && $this->listrakConfigService->getConfig('dataToken') && $this->listrakConfigService->getConfig('dataTokenExpiry') > time()) {
            return $this->listrakConfigService->getConfig('dataToken');
        }
        if ($type === self::EMAIL_INTEGRATION && $this->listrakConfigService->getConfig('emailToken') && $this->listrakConfigService->getConfig('emailTokenExpiry') > time()) {
            return $this->listrakConfigService->getConfig('emailToken');
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
                $this->listrakConfigService->setConfig('dataToken', $data['access_token'] ?? '');
                $this->listrakConfigService->setConfig('dataTokenExpiry', time() + 3599);

                return $this->listrakConfigService->getConfig('dataToken');
            }
            $this->listrakConfigService->setConfig('emailToken', $data['access_token'] ?? '');
            $this->listrakConfigService->setConfig('emailTokenExpiry', time() + 3599);

            return $this->listrakConfigService->getConfig('emailToken');
        }
        $this->logger->error('Unable to retrieve access token');

        return '';
    }

    /**
     * @return array<string,string>
     */
    private function buildAuthRequestBody(string $type): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $type === self::DATA_INTEGRATION ? $this->listrakConfigService->getConfig('dataClientId') : $this->listrakConfigService->getConfig('emailClientId'),
            'client_secret' => $type === self::DATA_INTEGRATION ? $this->listrakConfigService->getConfig('dataClientSecret') : $this->listrakConfigService->getConfig('emailClientSecret'),
        ];
    }

    private function handleError(GuzzleException $e): void
    {
        $this->logger->error(
            'Data sync failed: ' . $e->getMessage()
        );
    }
}
