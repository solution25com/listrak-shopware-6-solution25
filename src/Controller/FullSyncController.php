<?php declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Message\SyncCustomersMessage;
use Listrak\Message\SyncNewsletterRecipientsMessage;
use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class FullSyncController
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    #[Route(path: '/api/_action/listrak-customer-sync', name: 'api.action.listrak.customer-sync', methods: ['POST'])]
    public function syncCustomers(Context $context): JsonResponse
    {
        $success = ['success' => false];
        if (!$this->listrakConfigService->getConfig('dataClientId') || !$this->listrakConfigService->getConfig('dataClientSecret')) {
            return new JsonResponse($success);
        }
        try {
            $message = new SyncCustomersMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-order-sync', name: 'api.action.listrak.order-sync', methods: ['POST'])]
    public function syncOrders(Context $context): JsonResponse
    {
        $success = ['success' => false];
        if (!$this->listrakConfigService->getConfig('dataClientId') || !$this->listrakConfigService->getConfig('dataClientSecret')) {
            return new JsonResponse($success);
        }
        try {
            $message = new SyncOrdersMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-newsletter-recipient-sync', name: 'api.action.listrak.newsletter-recipient-sync', methods: ['POST'])]
    public function syncNewsletterRecipients(Context $context): JsonResponse
    {
        $success = ['success' => false];
        if (!$this->listrakConfigService->getConfig('emailClientId') || !$this->listrakConfigService->getConfig('emailClientSecret')) {
            return new JsonResponse($success);
        }
        try {
            $message = new SyncNewsletterRecipientsMessage($context);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }
}
