<?php

declare(strict_types=1);

namespace Listrak\Controller;

use GuzzleHttp\Client;
use Listrak\Service\ListrakApiService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ApiTestController
{
    public function __construct()
    {
    }

    #[Route(path: '/api/_action/listrak/data-api/test', name: 'api.action.listrak.data-api.test', methods: ['POST'])]
    public function checkDataApi(RequestDataBag $dataBag): JsonResponse
    {
        $clientId = $dataBag->get('Listrak.config.dataClientId') ?? null;
        $clientSecret = $dataBag->get('Listrak.config.dataClientSecret') ?? null;
        if (!$clientId || !$clientSecret) {
            throw new BadRequestHttpException('Missing client ID and/or client Secret');
        }
        $token = $this->getAccessToken($clientId, $clientSecret);

        return new JsonResponse($token);
    }

    #[Route(path: '/api/_action/listrak/email-api/test', name: 'api.action.listrak.email-api.test', methods: ['POST'])]
    public function checkEmailApi(RequestDataBag $dataBag): JsonResponse
    {
        $clientId = $dataBag->get('Listrak.config.emailClientId') ?? null;
        $clientSecret = $dataBag->get('Listrak.config.emailClientSecret') ?? null;
        if (!$clientId || !$clientSecret) {
            throw new BadRequestHttpException('Missing client ID and/or client Secret');
        }
        $token = $this->getAccessToken($clientId, $clientSecret);

        return new JsonResponse($token);
    }

    private function getAccessToken(string $clientId, string $clientSecret): string
    {
        $body = $this->buildAuthRequestBody($clientId, $clientSecret);
        $options = [
            'form_params' => $body,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
        ];

        $res = $this->request($options);
        $status = (int) $res['status'];
        if ($status === 401) {
            throw new UnauthorizedHttpException('API', 'Invalid API credentials.');
        }

        if ($status >= 400) {
            $desc = $res['json']['error'];
            throw new HttpException($status, 'Token endpoint error: ' . $desc);
        }

        $token = $res['json']['access_token'] ?? null;
        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException('Token endpoint response missing access_token.');
        }

        return $token;
    }

    private function request(array $options): array
    {
        $options += [
            'http_errors' => false,
        ];
        $client = new Client();
        $response = $client->request('POST', ListrakApiService::TOKEN_URL, $options);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        $json = json_decode($raw, true);
        if ($json === null && $raw !== 'null') {
            $json = [];
        }

        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }

    private function buildAuthRequestBody(string $clientId, string $clientSecret): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }
}
