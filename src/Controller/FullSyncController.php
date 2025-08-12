<?php

declare(strict_types=1);

namespace Listrak\Controller;

use Listrak\Message\SyncCustomersMessage;
use Listrak\Message\SyncNewsletterRecipientsMessage;
use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class FullSyncController
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityRepository $salesChannelRepository,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
    ) {
    }

    #[Route(path: '/api/_action/listrak-customer-sync', name: 'api.action.listrak.customer-sync', methods: ['POST'])]
    public function syncCustomers(Context $context): JsonResponse
    {
        $success = ['success' => false];
        try {
            $criteria = new Criteria();
            $salesChannels = $this->salesChannelRepository->search($criteria, $context);
            $salesChannelContexts = [];
            /** @var SalesChannelEntity $salesChannel */
            foreach ($salesChannels as $salesChannel) {
                $salesChannelContexts[] = $this->salesChannelContextFactory->create(
                    Uuid::randomHex(),
                    $salesChannel->getId(),
                );
            }
            /** @var SalesChannelContext $salesChannelContext */
            foreach ($salesChannelContexts as $salesChannelContext) {
                if (
                    !$this->listrakConfigService->getConfig(
                        'dataClientId',
                        $salesChannelContext->getSalesChannelId()
                    ) || !$this->listrakConfigService->getConfig('dataClientSecret', $salesChannelContext->getSalesChannelId())
                ) {
                    return new JsonResponse($success);
                }
                $message = new SyncCustomersMessage(0, 500, null, $salesChannelContext->getSalesChannelId());
                $this->messageBus->dispatch($message);
            }
        } catch (ExceptionInterface $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-order-sync', name: 'api.action.listrak.order-sync', methods: ['POST'])]
    public function syncOrders(Context $context): JsonResponse
    {
        $success = ['success' => false];
        try {
            $criteria = new Criteria();
            $salesChannels = $this->salesChannelRepository->search($criteria, $context);
            $salesChannelContexts = [];
            /** @var SalesChannelEntity $salesChannel */
            foreach ($salesChannels as $salesChannel) {
                $salesChannelContexts[] = $this->salesChannelContextFactory->create(
                    Uuid::randomHex(),
                    $salesChannel->getId(),
                );
            }
            /** @var SalesChannelContext $salesChannelContext */
            foreach ($salesChannelContexts as $salesChannelContext) {
                $message = new SyncOrdersMessage(0, 500, null, $salesChannelContext->getSalesChannelId());
                $this->messageBus->dispatch($message);
            }
        } catch (ExceptionInterface $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }

    #[Route(path: '/api/_action/listrak-newsletter-recipient-sync', name: 'api.action.listrak.newsletter-recipient-sync', methods: ['POST'])]
    public function syncNewsletterRecipients(Context $context): JsonResponse
    {
        $success = ['success' => false];
        try {
            $criteria = new Criteria();
            $salesChannels = $this->salesChannelRepository->search($criteria, $context);
            $salesChannelContexts = [];
            /** @var SalesChannelEntity $salesChannel */
            foreach ($salesChannels as $salesChannel) {
                $salesChannelContexts[] = $this->salesChannelContextFactory->create(
                    Uuid::randomHex(),
                    $salesChannel->getId(),
                );
            }
            /** @var SalesChannelContext $salesChannelContext */
            foreach ($salesChannelContexts as $salesChannelContext) {
                if (
                    !$this->listrakConfigService->getConfig(
                        'emailClientId',
                        $salesChannelContext->getSalesChannelId()
                    ) || !$this->listrakConfigService->getConfig('emailClientSecret', $salesChannelContext->getSalesChannelId())
                ) {
                    return new JsonResponse($success);
                }

                $message = new SyncNewsletterRecipientsMessage($salesChannelContext->getSalesChannelId());
                $this->messageBus->dispatch($message);
            }
        } catch (ExceptionInterface $e) {
            return new JsonResponse($success);
        }
        $success = ['success' => true];

        return new JsonResponse($success);
    }
}
