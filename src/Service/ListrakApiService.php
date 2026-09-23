<?php

declare(strict_types=1);

namespace Listrak\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Library\Endpoints;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ListrakApiService extends Endpoints
{
    public const EMAIL_INTEGRATION = 'EMAIL';
    public const DATA_INTEGRATION = 'DATA';
    public const TOKEN_URL = 'https://auth.listrak.com/OAuth2/Token';

    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $tokens = [];

    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly FailedRequestService $failedRequestService,
        private readonly LoggerInterface $logger,
        private ?ClientInterface $http = null
    ) {
    }

    /**
     * @param list<array> $data
     */
    public function exportCustomer(
        array $data,
        SalesChannelContext $salesChannelContext,
    ): void {
        $fullEndpointUrl = Endpoints::getUrl(Endpoints::CUSTOMER_IMPORT);
        $this->logger->debug(
            'Exporting customers',
            ['salesChannelId' => $salesChannelContext->getSalesChannelId()]
        );
        $options = $this->jsonOptions($data);
        $this->authorizedRequest($fullEndpointUrl, $options, $salesChannelContext, self::DATA_INTEGRATION);
        $this->failedRequestService->flushFailedRequests($salesChannelContext);
    }

    /**
     * @param list<array> $data
     */
    public function exportOrder(array $data, SalesChannelContext $salesChannelContext): void
    {
        $fullEndpointUrl = Endpoints::getUrl(Endpoints::ORDER_IMPORT);
        $this->logger->debug(
            'Exporting orders',
            ['salesChannelId' => $salesChannelContext->getSalesChannelId()]
        );
        $options = $this->jsonOptions($data);
        $this->authorizedRequest($fullEndpointUrl, $options, $salesChannelContext, self::DATA_INTEGRATION);
        $this->failedRequestService->flushFailedRequests($salesChannelContext);
    }

    public function createOrUpdateContact(array $data, SalesChannelContext $salesChannelContext): void
    {
        $listId = $this->listrakConfigService->getConfig('listId', $salesChannelContext->getSalesChannelId());
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(
                Endpoints::CONTACT_CREATE,
                [$listId, 'Contact'],
                ['overrideUnsubscribe' => 'true']
            );
            $this->logger->debug(
                'Creating contact',
                [
                    'listId' => $listId,
                    'salesChannelId' => $salesChannelContext->getSalesChannelId(),
                ]
            );
            $options = $this->jsonOptions($data);

            $this->authorizedRequest($fullEndpointUrl, $options, $salesChannelContext, self::EMAIL_INTEGRATION);
            $this->failedRequestService->flushFailedRequests($salesChannelContext);
        }
    }

    public function startListImport(array $data, SalesChannelContext $salesChannelContext): void
    {
        $listId = trim((string) $this->listrakConfigService->getConfig('listId', $salesChannelContext->getSalesChannelId()));
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(Endpoints::START_LIST_IMPORT, [$listId, 'ListImport']);
            $this->logger->debug(
                'Creating list import',
                [
                    'listId' => $listId,
                    'salesChannelId' => $salesChannelContext->getSalesChannelId(),
                ]
            );
            $options = $this->jsonOptions($data);
            $this->authorizedRequest($fullEndpointUrl, $options, $salesChannelContext, self::EMAIL_INTEGRATION);
            $this->failedRequestService->flushFailedRequests($salesChannelContext);
        }
    }

    public function sendTransactionalMessage(
        string|int $transactionalMessageId,
        array $data,
        SalesChannelContext $salesChannelContext,
    ): void {
        $listId = trim(
            (string) $this->listrakConfigService->getConfig('transactionalListId', $salesChannelContext->getSalesChannelId())
        );
        if ($listId) {
            $fullEndpointUrl = Endpoints::getUrlDynamicParam(
                Endpoints::START_LIST_IMPORT,
                [$listId, 'TransactionalMessage', $transactionalMessageId, 'Message']
            );
            $this->logger->debug(
                'Sending transactional message',
                ['listId' => $listId, 'salesChannelId' => $salesChannelContext->getSalesChannelId()]
            );
            foreach ($data as $message) {
                $options = $this->jsonOptions($message);
                $this->authorizedRequest($fullEndpointUrl, $options, $salesChannelContext, self::EMAIL_INTEGRATION);
            }
            $this->failedRequestService->flushFailedRequests($salesChannelContext);
        }
    }

    /**
     * Send a business request with channel-specific credentials. Only the final
     * outcome is recorded, so a refreshed 401 never creates a duplicate retry.
     */
    public function authorizedRequest(
        array $endpoint,
        array $options,
        SalesChannelContext $ctx,
        string $type,
        ?FailedRequestEntity $failed = null
    ): ?string {
        $this->assertIntegrationType($type);
        $options = FailedRequestService::withoutCredentials($options);
        $token = $this->getAccessToken($type, $ctx);
        $result = ['status' => 0, 'body' => null];

        if ($token !== '') {
            $result = $this->send($endpoint, $this->withToken($options, $token));
            if ($result['status'] === 401) {
                $token = $this->refreshAccessToken($type, $ctx);
                $result = $token === ''
                    ? ['status' => 0, 'body' => null]
                    : $this->send($endpoint, $this->withToken($options, $token));
            }
        }

        $decoded = json_decode($result['body'] ?? '', true);
        $success = $result['status'] >= 200 && $result['status'] < 300
            && !(\is_array($decoded) && !empty($decoded['error']));

        if ($success) {
            $this->failedRequestService->removeFromFailedRequests($ctx, $failed);
        } else {
            // Do not persist token responses, credentials, or customer data from errors.
            $reason = $result['status'] === 0 ? 'Authentication or transport failure' : 'HTTP ' . $result['status'];
            if ($failed !== null) {
                $failed->setResponse($reason);
                $failed->setOptions($options);
                $this->failedRequestService->updateFailedRequest($failed);
            } else {
                $this->failedRequestService->saveRequestToFailedRequests(
                    $endpoint['url'], $endpoint['method'], $options, $reason, $ctx, $type
                );
            }
        }

        return $result['body'];
    }

    /**
     * Backwards-compatible business-request entry point. Authentication requests
     * are deliberately excluded from the persistent retry queue.
     */
    public function request(
        array $endpoint,
        array $options,
        SalesChannelContext $salesChannelContext,
        ?FailedRequestEntity $failed = null
    ): ?string {
        if ($endpoint['url'] === self::TOKEN_URL) {
            return $this->send($endpoint, $options)['body'];
        }
        $type = $failed?->getIntegrationType()
            ?? (str_starts_with($endpoint['url'], 'https://api.listrak.com/data/') ? self::DATA_INTEGRATION : self::EMAIL_INTEGRATION);

        return $this->authorizedRequest($endpoint, $options, $salesChannelContext, $type, $failed);
    }

    public function getAccessToken(string $type, SalesChannelContext $sc): string
    {
        $key = $this->tokenKey($type, $sc);
        $cached = $this->tokens[$key] ?? null;
        if ($cached !== null && $cached['expiresAt'] > time() + 60) {
            return $cached['token'];
        }

        return $this->refreshAccessToken($type, $sc);
    }

    /** @return array{status: int, body: ?string} */
    private function send(array $endpoint, array $options): array
    {
        $this->http ??= new Client();
        $options = array_replace($options, ['http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]);
        try {
            $response = $this->http->request($endpoint['method'], $endpoint['url'], $options);
            $status = $response->getStatusCode();
            $this->logger->log($status >= 400 ? 'warning' : 'debug', 'Listrak HTTP response', [
                'method' => $endpoint['method'], 'status' => $status,
            ]);

            return ['status' => $status, 'body' => (string) $response->getBody()];
        } catch (GuzzleException $exception) {
            // Guzzle messages may include request credentials or response payloads.
            $this->logger->error('Listrak HTTP transport failure', ['exceptionClass' => $exception::class]);

            return ['status' => 0, 'body' => null];
        }
    }

    private function refreshAccessToken(string $type, SalesChannelContext $sc): string
    {
        $key = $this->tokenKey($type, $sc);
        unset($this->tokens[$key]);
        $credentials = $this->buildAuthRequestBody($type, $sc->getSalesChannelId());
        if ($credentials['client_id'] === '' || $credentials['client_secret'] === '') {
            return '';
        }
        $response = $this->send(['method' => 'POST', 'url' => self::TOKEN_URL], [
            'form_params' => $credentials,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $data = json_decode($response['body'] ?? '', true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !\is_array($data)
            || !empty($data['error']) || !\is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            $this->logger->warning('Listrak authentication failed', ['type' => $type, 'salesChannelId' => $sc->getSalesChannelId()]);

            return '';
        }
        $this->tokens[$key] = [
            'token' => $data['access_token'],
            'expiresAt' => time() + max(1, (int) ($data['expires_in'] ?? 3599)),
        ];

        return $data['access_token'];
    }

    private function tokenKey(string $type, SalesChannelContext $sc): string
    {
        $credentials = $this->buildAuthRequestBody($type, $sc->getSalesChannelId());

        return $sc->getSalesChannelId() . ':' . $type . ':' . hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    private function assertIntegrationType(string $type): void
    {
        if (!\in_array($type, [self::DATA_INTEGRATION, self::EMAIL_INTEGRATION], true)) {
            throw new \InvalidArgumentException('Unknown Listrak integration type.');
        }
    }

    /** @return array<string,string> */
    private function buildAuthRequestBody(string $type, string $salesChannelId): array
    {
        $this->assertIntegrationType($type);
        $prefix = $type === self::DATA_INTEGRATION ? 'data' : 'email';

        return [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $this->listrakConfigService->getConfig($prefix . 'ClientId', $salesChannelId),
            'client_secret' => (string) $this->listrakConfigService->getConfig($prefix . 'ClientSecret', $salesChannelId),
        ];
    }

    private function withToken(array $options, string $token): array
    {
        $options['headers']['Authorization'] = 'Bearer ' . $token;

        return $options;
    }

    private function jsonOptions(array $data, array $extraHeaders = []): array
    {
        return [
            'headers' => array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $extraHeaders),
            'body' => json_encode($data, JSON_THROW_ON_ERROR),
        ];
    }
}
