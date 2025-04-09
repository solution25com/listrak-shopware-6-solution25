<?php declare(strict_types=1);

namespace Solu1Listrak\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class ListrakApiService
{
    private LoggerInterface $logger;
    private Client $client;
    private const TOKEN_URL = 'https://auth.listrak.com/OAuth2/Token';
    private const BASE_URL = 'https://api.listrak.com/';
    private ?string $accessToken = null;
    private ListrakConfigService $listrakConfigService;

    public function __construct(ListrakConfigService $listrakConfig, LoggerInterface $logger)
    {
        $this->listrakConfig = $listrakConfig;
        $this->logger = $logger;
        $this->client = new Client();
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $body = $this->buildAuthRequestBody();

        $options = [
            'form_params' => $body,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ];

        $res = $this->request(['GET',self::TOKEN_URL ], $options);

        if ($res instanceof Response) {
            $responseBody = $res->getBody()->getContents();
            $decodedBody = json_decode($responseBody, true);

            if (isset($decodedBody['error']) && $decodedBody['error']) {
                return "Error: " . ($decodedBody['message'] ?? 'Unknown error');
            }

            $this->accessToken = $decodedBody['data']['access_token'] ?? 'Unknown error';

            return $this->accessToken;
        }

        return "Error: Invalid response received";
    }

    private function buildAuthRequestBody(): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $this->listrakConfigService->getConfig('clientId'),
            'client_secret' => $this->listrakConfigService->getConfig('clientSecret'),
        ];
    }

    private function ApiResponse(ResponseInterface $response): ResponseInterface | array
    {
        $responseBody = $response->getBody()->getContents();
        $decodedResponse = json_decode($responseBody, true);

        if (isset($decodedResponse['error']) && $decodedResponse['error']) {
            return [
                'error' => true,
                'message' => $decodedResponse['message'] ?? 'Unknown error',
            ];
        }

        return [
            'error' => false,
            'message' => 'Success',
            'data' => $decodedResponse['data'],
        ];
    }

    private function request(array $endpoint, array $options): ResponseInterface|array
    {
        try {
            ['method' => $method, 'url' => $url] = $endpoint;
            return $this->client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            return $this->handleError($e);
        }
    }

    private function handleError(GuzzleException $e): array
    {
        $response = $e->hasResponse() ? $e->getResponse() : null;
        if ($response) {
            $responseBody = $response->getBody()->getContents();
            $decodedBody = json_decode($responseBody, true);
            return [
                'error' => true,
                'code' => $e->getCode(),
                'message' => $decodedBody['message'] ?? $decodedBody,
            ];
        }

        return [
            'error' => true,
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ];
    }
}
