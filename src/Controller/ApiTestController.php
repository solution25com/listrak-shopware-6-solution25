<?php declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Service\ListrakApiService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ApiTestController
{
    public function __construct(
        private readonly ListrakApiService $listrakApiService,
    ) {
    }

    #[Route(path: '/api/_action/listrak-data-api/test', name: 'api.action.listrak-data-api.test', methods: ['POST'])]
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

    #[Route(path: '/api/_action/listrak-email-api/test', name: 'api.action.listrak-email-api.test', methods: ['POST'])]
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

    private function getAccessToken(string $clientId, string $clientSecret): ?string
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
                throw new UnauthorizedHttpException('Listrak API', 'The provided API credentials are invalid.');
            }

            return $data['access_token'];
        }
        throw new UnauthorizedHttpException('Listrak API', 'The provided API credentials are invalid.');
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
