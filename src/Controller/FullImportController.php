<?php

namespace Listrak\Controller;

use Listrak\Message\ExportCustomersMessage;
use Listrak\Message\ImportCustomersMessage;
use Listrak\Message\ImportOrdersMessage;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\Request;

#[Route(defaults: ['_routeScope' => ['api']])]
class FullImportController
{
    private ListrakConfigService $listrakConfigService;
    private ListrakApiService $listrakApiService;

    public function __construct(
        ListrakConfigService $listrakConfig,
        ListrakApiService $listrakApi,
        EntityRepository $failedRequestRepository,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $orderRepository,
        private readonly MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfig;
        $this->listrakApiService = $listrakApi;
        $this->failedRequestRepository = $failedRequestRepository;
        $this->logger = $logger;
    }

    #[Route(path: '/api/_action/listrak-customer-sync', name: 'api.action.listrak.customer-sync', methods: ['POST'])]
    public function importCustomers(Request $request, Context $context): JsonResponse
    {
        if(!$this->listrakConfigService->getConfig('dataClientId') || !$this->listrakConfigService->getConfig('dataClientSecret')) {
            $success = ['success' => false];
            return new JsonResponse($success);
        }
        try {
            $message = new ImportCustomersMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $success = ['success' => false];
            return new JsonResponse($success);
        }
        $success = ['success' => true];
        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-order-sync', name: 'api.action.listrak.order-sync', methods: ['POST'])]
    public function importOrders(Request $request, Context $context): JsonResponse
    {
        if(!$this->listrakConfigService->getConfig('dataClientId') || !$this->listrakConfigService->getConfig('dataClientSecret')) {
            $success = ['success' => false];
            return new JsonResponse($success);
        }
        try {
            $message = new ImportOrdersMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $success = ['success' => false];
            return new JsonResponse($success);
        }
        $success = ['success' => true];
        return new JsonResponse($success);
    }
}
