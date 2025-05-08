<?php declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function checkDataApi(RequestDataBag $dataBag): JsonResponse
    {
        return new JsonResponse($this->checkDataApiCredentials($dataBag));
    }

    #[Route(path: '/api/_action/listrak-email-api-test/verify', name: 'api.action.listrak-email-api-test.verify', methods: ['POST'])]
    public function checkEmailApi(RequestDataBag $dataBag): JsonResponse
    {
        return new JsonResponse($this->checkEmailApiCredentials($dataBag));
    }

    public function checkDataApiCredentials(RequestDataBag $dataBag): array
    {
        $token = $this->listrakApiService->getAccessToken($this->listrakApiService::DATA_INTEGRATION);
        $success = ['success' => false];

        if (str_contains($token, 'Error:')) {
            return $success;
        }
        $success = ['success' => true];

        return $success;
    }

    public function checkEmailApiCredentials(RequestDataBag $dataBag): array
    {
        $token = $this->listrakApiService->getAccessToken($this->listrakApiService::EMAIL_INTEGRATION);
        $success = ['success' => false];
        if (str_contains($token, 'Error:')) {
            return $success;
        }
        $success = ['success' => true];

        return $success;
    }
}
