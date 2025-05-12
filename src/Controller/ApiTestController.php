<?php declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ApiTestController
{
    private ListrakApiService $listrakApiService;

    private LoggerInterface $logger;

    public function __construct(
        ListrakApiService $listrakApiService,
        LoggerInterface $logger
    ) {
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    #[Route(path: '/api/_action/listrak-data-api-test/verify', name: 'api.action.listrak-data-api-test.verify', methods: ['POST'])]
    public function checkDataApi(Request $request): JsonResponse
    {
        return new JsonResponse($this->checkDataApiCredentials($request));
    }

    #[Route(path: '/api/_action/listrak-email-api-test/verify', name: 'api.action.listrak-email-api-test.verify', methods: ['POST'])]
    public function checkEmailApi(Request $request): JsonResponse
    {
        return new JsonResponse($this->checkEmailApiCredentials($request));
    }

    public function checkDataApiCredentials(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        $clientId = $data['Listrak.config.dataClientId'] ?? null;
        $clientSecret = $data['Listrak.config.dataClientSecret'] ?? null;
        $success = ['success' => false];
        if (!$clientId || !$clientSecret) {
            return $success;
        }
        $token = $this->getAccessToken($clientId, $clientSecret);

        if (str_contains($token, 'Error:')) {
            return $success;
        }
        $success = ['success' => $token];

        return $success;
    }

    public function checkEmailApiCredentials(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        $clientId = $data['Listrak.config.emailClientId'] ?? null;
        $clientSecret = $data['Listrak.config.emailClientSecret'] ?? null;
        $success = ['success' => false];
        if (!$clientId || !$clientSecret) {
            return $success;
        }
        $token = $this->getAccessToken($clientId, $clientSecret);

        if (str_contains($token, 'Error:')) {
            return $success;
        }
        $success = ['success' => $token];

        return $success;
    }

    public function getAccessToken(string $clientId, string $clientSecret): string
    {
        $body = $this->buildAuthRequestBody($clientId, $clientSecret);
        $options = [
            'form_params' => $body,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ];

        $responseContent = $this->listrakApiService->request([
            'method' => 'POST',
            'url' => ListrakApiService::TOKEN_URL,
        ], $options);
        if ($responseContent) {
            $data = json_decode($responseContent, true);
            if (isset($data['error']) && $data['error']) {
                return 'Error: ' . ($data['message'] ?? 'Unknown error');
            }

            return $data['access_token'];
        }

        return 'Error: Unknown error';
    }

    public function buildAuthRequestBody(string $clientId, string $clientSecret): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }
}
