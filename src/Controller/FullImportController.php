<?php declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Message\ImportCustomersMessage;
use Listrak\Message\ImportNewsletterRecipientsMessage;
use Listrak\Message\ImportOrdersMessage;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class FullImportController
{
    private ListrakConfigService $listrakConfigService;

    private ListrakApiService $listrakApiService;

    private LoggerInterface $logger;

    public function __construct(
        ListrakConfigService $listrakConfigService,
        ListrakApiService $listrakApiService,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $orderRepository,
        private readonly MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfigService;
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    #[Route(path: '/api/_action/listrak-customer-sync', name: 'api.action.listrak.customer-sync', methods: ['POST'])]
    public function importCustomers(Request $request, Context $context): JsonResponse
    {
        if (!$this->listrakConfigService->getConfig('dataClientId') || !$this->listrakConfigService->getConfig('dataClientSecret')) {
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
        $success = ['success' => false];
        if (!$this->listrakConfigService->getConfig('dataClientId') || !$this->listrakConfigService->getConfig('dataClientSecret')) {
            return new JsonResponse($success);
        }
        try {
            $message = new ImportOrdersMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-newsletter-recipient-sync', name: 'api.action.listrak.newsletter-recipient-sync', methods: ['POST'])]
    public function importNewsletterRecipients(Request $request, Context $context): JsonResponse
    {
        $success = ['success' => false];
        if (!$this->listrakConfigService->getConfig('emailClientId') || !$this->listrakConfigService->getConfig('emailClientSecret')) {
            return new JsonResponse($success);
        }
        try {
            $message = new ImportNewsletterRecipientsMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            return new JsonResponse($success);
        }

        $success = ['success' => true];

        return new JsonResponse($success);
    }
}
