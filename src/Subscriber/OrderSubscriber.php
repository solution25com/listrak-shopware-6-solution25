<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $orderIds = [];

        foreach ($event->getWriteResults() as $writeResult) {
            $id = $writeResult->getPrimaryKey();
            if ($id) {
                $orderIds[] = $id;
            }
        }

        if (empty($orderIds)) {
            return;
        }

        $criteria = new Criteria($orderIds);
        $orders = $this->orderRepository->search($criteria, $event->getContext());

        $salesChannelOrderMap = [];

        /** @var OrderEntity $order */
        foreach ($orders as $order) {
            $salesChannelId = $order->getSalesChannelId();

            if (!$this->listrakConfigService->isDataSyncEnabled('enableOrderSync', $salesChannelId)) {
                $this->logger->debug('Order sync skipped — sync not enabled for SalesChannel', [
                    'orderId' => $order->getId(),
                    'salesChannelId' => $salesChannelId,
                ]);
                continue;
            }

            $salesChannelOrderMap[$salesChannelId][] = $order->getId();
        }

        foreach ($salesChannelOrderMap as $salesChannelId => $orderIdsForChannel) {
            try {
                $message = new SyncOrdersMessage(0, 500, $orderIdsForChannel, $salesChannelId);
                $this->messageBus->dispatch($message);
            } catch (\Exception $e) {
                $this->logger->error('Failed to dispatch SyncOrdersMessage', [
                    'salesChannelId' => $salesChannelId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
