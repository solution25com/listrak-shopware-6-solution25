<?php

declare(strict_types=1);

namespace Listrak\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Library\Endpoints;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ListrakApiService extends Endpoints
{
    public const EMAIL_INTEGRATION = 'EMAIL';
    public const DATA_INTEGRATION = 'DATA';
    public const TOKEN_URL = 'https://auth.listrak.com/OAuth2/Token';

    private ?string $dataToken = null;

    private ?int $dataTokenExp = null;

    private ?string $emailToken = null;

    private ?int $emailTokenExp = null;

    private ?Client $http = null;

    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly FailedRequestService $failedRequestService,
        private readonly LoggerInterface $logger
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
        $listId = trim($this->listrakConfigService->getConfig('listId', $salesChannelContext->getSalesChannelId()));
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
        $transactionalMessageId,
        array $data,
        ?SalesChannelContext $salesChannelContext,
    ): void {
        $listId = trim(
            $this->listrakConfigService->getConfig('transactionalListId', $salesChannelContext->getSalesChannelId())
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
     * Generic HTTP request (no auth). Returns body even on API errors; returns null on transport error.
     *
     * @param array{method:string,url:string} $endpoint
     * @param array<string,mixed> $options
     */
    public function request(
        array $endpoint,
        array $options,
        SalesChannelContext $salesChannelContext,
        ?FailedRequestEntity $failed = null
    ): ?string {
        $cid = $this->cid();
        ['method' => $method, 'url' => $url] = $endpoint;
        $options = array_replace(['http_errors' => false, 'timeout' => 30], $options);

        $this->logger->debug('HTTP request: start', [
            'cid' => $cid,
            'method' => $method,
            'url' => $url,
            'options' => $this->sanitizeOptions($options),
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
        ]);

        $start = microtime(true);

        try {
            $resp = $this->http()->request($method, $url, $options);
            $status = $resp->getStatusCode();
            $body = (string) $resp->getBody();
            $dur = (int) ((microtime(true) - $start) * 1000);

            $decoded = json_decode($body, true);
            $jsonErr = (json_last_error() !== \JSON_ERROR_NONE) ? json_last_error_msg() : null;
            $apiErr = \is_array($decoded) ? $this->extractError($decoded) : null;

            $ctx = ['cid' => $cid, 'method' => $method, 'url' => $url, 'status' => $status, 'durationMs' => $dur];

            if ($jsonErr) {
                $this->logger->warning(
                    'HTTP response: invalid JSON',
                    $ctx + [
                        'jsonError' => $jsonErr,
                        'bodyPreview' => $this->trunc($body),
                    ]
                );
            }

            if ($status >= 200 && $status < 300 && !$apiErr) {
                $this->logger->debug(
                    'HTTP request: success',
                    $ctx + [
                        'bodyPreview' => $this->trunc($body),
                    ]
                );
                $this->failedRequestService->removeFromFailedRequests($salesChannelContext, $failed);
            } else {
                $level = ($status >= 400 && $status < 500) ? 'warning' : 'error';
                $this->logger->log(
                    $level,
                    'HTTP request: API error',
                    $ctx + [
                        'error' => $apiErr ?? ('HTTP ' . $status),
                        'bodyPreview' => $this->trunc($body),
                    ]
                );
                $payloadForFailed = $body ?: ($apiErr ?? 'Unknown error');
                if ($failed) {
                    $failed->setResponse($payloadForFailed);
                    $this->failedRequestService->updateFailedRequest($failed);
                } else {
                    $this->failedRequestService->saveRequestToFailedRequests(
                        $url,
                        $method,
                        $options,
                        $payloadForFailed,
                        $salesChannelContext
                    );
                }
            }

            return $body;
        } catch (GuzzleException $e) {
            $dur = (int) ((microtime(true) - $start) * 1000);
            $errorBody = null;
            if ($e instanceof RequestException && $e->hasResponse()) {
                $errorBody = (string) $e->getResponse()->getBody();
            }

            $this->logger->error('HTTP request: transport failure', [
                'cid' => $cid,
                'method' => $method,
                'url' => $url,
                'durationMs' => $dur,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'bodyPreview' => $this->trunc($errorBody),
            ]);

            $payloadForFailed = $errorBody ?? $e->getMessage();
            if ($failed) {
                $failed->setResponse($payloadForFailed);
                $this->failedRequestService->updateFailedRequest($failed);
            } else {
                $this->failedRequestService->saveRequestToFailedRequests(
                    $url,
                    $method,
                    $options,
                    $payloadForFailed,
                    $salesChannelContext
                );
            }

            return null;
        }
    }

    public function authorizedRequest(
        array $endpoint,
        array $options,
        SalesChannelContext $ctx,
        string $type,
        ?FailedRequestEntity $failed = null
    ): ?string {
        $token = $this->getAccessToken($type, $ctx);
        $options['headers']['Authorization'] = 'Bearer ' . $token;

        $body = $this->request($endpoint, $options, $ctx, $failed);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        $status = \is_array($decoded) ? (int) ($decoded['status'] ?? 0) : null;

        if ($status === 401) {
            $token = $this->refreshAccessToken($type, $ctx);
            $options['headers']['Authorization'] = 'Bearer ' . $token;

            return $this->request($endpoint, $options, $ctx, $failed);
        }

        return $body;
    }

    public function getAccessToken(string $type, SalesChannelContext $sc): string
    {
        $now = time();
        $skew = 60;
        if ($type === self::DATA_INTEGRATION && $this->dataToken && $this->dataTokenExp && $this->dataTokenExp > ($now + $skew)) {
            return $this->dataToken;
        }
        if ($type === self::EMAIL_INTEGRATION && $this->emailToken && $this->emailTokenExp && $this->emailTokenExp > ($now + $skew)) {
            return $this->emailToken;
        }

        return $this->refreshAccessToken($type, $sc);
    }

    private function http(): Client
    {
        return $this->http ??= new Client([
            'http_errors' => false,
        ]);
    }

    private function cid(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function sanitizeOptions(array $options): array
    {
        $opt = $options;
        $mask = static fn ($v) => \is_string($v) ? mb_substr($v, 0, 6) . '***' : '***';
        if (isset($opt['headers'])) {
            foreach (['Authorization', 'authorization', 'X-Api-Key', 'x-api-key'] as $h) {
                if (isset($opt['headers'][$h])) {
                    $opt['headers'][$h] = $mask($opt['headers'][$h]);
                }
            }
        }
        if (isset($opt['form_params'])) {
            foreach (['password', 'client_secret', 'refresh_token'] as $k) {
                if (isset($opt['form_params'][$k])) {
                    $opt['form_params'][$k] = $mask($opt['form_params'][$k]);
                }
            }
        }

        return $opt;
    }

    private function trunc(?string $s, int $limit = 1500): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = $this->redactTokens($s);

        return mb_strlen($s) > $limit ? (mb_substr($s, 0, $limit) . '…[truncated]') : $s;
    }

    private function redactTokens(string $s): string
    {
        $s = preg_replace(
            '/([?&]|^)(access_token|refresh_token)=([^&#\s]+)/i',
            '$1$2=[REDACTED]',
            $s
        );

        $s = preg_replace(
            '/("(?:access_token|refresh_token)"\s*:\s*)"([^"]*)"/i',
            '$1"[REDACTED]"',
            $s
        );

        $s = preg_replace(
            '/(\'(?:access_token|refresh_token)\'\s*:\s*)\'([^\']*)\'/i',
            '$1\'[REDACTED]\'',
            $s
        );

        $s = preg_replace(
            '/(Authorization\s*:\s*Bearer\s+)[A-Za-z0-9._~+\-\/=]+/i',
            '$1[REDACTED]',
            $s
        );

        return $s;
    }

    private function extractError(array $decoded): ?string
    {
        if (isset($decoded['error'])) {
            return $decoded['message'] ?? null;
        }

        return null;
    }

    private function refreshAccessToken(string $type, SalesChannelContext $sc): string
    {
        $this->logger->debug('AccessToken: fetching new token', ['type' => $type]);

        $body = $this->buildAuthRequestBody($type, $sc->getSalesChannelId());
        $options = [
            'form_params' => $body,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ];

        $resp = $this->request(
            ['method' => 'POST', 'url' => self::TOKEN_URL],
            $options,
            $sc
        );

        if (!$resp) {
            $this->logger->error('AccessToken: empty/failed token response');

            return '';
        }

        $data = json_decode($resp, true);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            $this->logger->error('AccessToken: invalid JSON from token endpoint', [
                'jsonError' => json_last_error_msg(),
                'bodyPreview' => $this->trunc($resp),
            ]);

            return '';
        }

        $err = $this->extractError($data);
        if ($err) {
            $this->logger->error('AccessToken: API error', ['error' => $err, 'bodyPreview' => $this->trunc($resp)]);

            return '';
        }

        $token = $data['access_token'] ?? '';
        $ttl = (int) ($data['expires_in'] ?? 3599);
        if ($token === '') {
            $this->logger->error('AccessToken: missing access_token in response');

            return '';
        }

        $exp = time() + max(1, $ttl);
        if ($type === self::DATA_INTEGRATION) {
            $this->dataToken = $token;
            $this->dataTokenExp = $exp;
        } else {
            $this->emailToken = $token;
            $this->emailTokenExp = $exp;
        }

        $this->logger->debug('AccessToken: obtained and stored', ['type' => $type, 'ttlSec' => $ttl]);

        return $token;
    }

    /**
     * @return array<string,string>
     */
    private function buildAuthRequestBody(string $type, ?string $salesChannelId = null): array
    {
        $dataClientId = $this->listrakConfigService->getConfig('dataClientId', $salesChannelId);
        $dataClientSecret = $this->listrakConfigService->getConfig('dataClientSecret', $salesChannelId);
        $emailClientId = $this->listrakConfigService->getConfig('emailClientId', $salesChannelId);
        $emailClientSecret = $this->listrakConfigService->getConfig('emailClientSecret', $salesChannelId);

        return [
            'grant_type' => 'client_credentials',
            'client_id' => $type === self::DATA_INTEGRATION ? $dataClientId : $emailClientId,
            'client_secret' => $type === self::DATA_INTEGRATION ? $dataClientSecret : $emailClientSecret,
        ];
    }

    private function jsonOptions(array $data, array $extraHeaders = []): array
    {
        return [
            'headers' => array_merge(
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $extraHeaders
            ),
            'body' => json_encode($data),
        ];
    }
}
