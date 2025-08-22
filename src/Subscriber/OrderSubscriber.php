<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
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
        /** @var list<string> $orderIds */
        $orderIds = $event->getIds();
        if ($orderIds === []) {
            return;
        }

        $criteria = (new Criteria($orderIds))->addFields(['id', 'salesChannelId']);
        $orders = $this->orderRepository->search($criteria, $event->getContext())->getEntities();

        /** @var array<string,string> $orderIdToSalesChannel */
        $orderIdToSalesChannel = [];

        /** @var PartialEntity $order */
        foreach ($orders as $order) {
            $oid = $order->get('id');
            $sc = $order->get('salesChannelId');
            if (\is_string($oid) && $oid !== '' && \is_string($sc) && $sc !== '') {
                $orderIdToSalesChannel[$oid] = $sc;
            }
        }

        if ($orderIdToSalesChannel === []) {
            return;
        }

        /** @var array<string,bool> $enabledCache */
        $enabledCache = [];
        /** @var array<string,list<string>> $salesChannelOrderMap */
        $salesChannelOrderMap = [];
        $this->logger->debug('Order written event triggered');
        foreach ($orderIdToSalesChannel as $orderId => $salesChannelId) {
            if (!\array_key_exists($salesChannelId, $enabledCache)) {
                $enabledCache[$salesChannelId] = $this->listrakConfigService->isDataSyncEnabled('enableOrderSync', $salesChannelId);
            }
            if (!$enabledCache[$salesChannelId]) {
                $this->logger->notice('Order sync skipped — not enabled for sales channel', [
                    'orderId' => $orderId,
                    'event' => OrderEvents::ORDER_WRITTEN_EVENT,
                    'salesChannelId' => $salesChannelId,
                ]);
                continue;
            }
            $salesChannelOrderMap[$salesChannelId][] = $orderId;
        }

        if ($salesChannelOrderMap === []) {
            return;
        }

        $batchSize = 300;
        foreach ($salesChannelOrderMap as $salesChannelId => $ids) {
            foreach (array_chunk($ids, $batchSize) as $chunk) {
                try {
                    $this->messageBus->dispatch(new SyncOrdersMessage(0, $batchSize, $chunk, $chunk[0], $salesChannelId));
                } catch (\Throwable $e) {
                    $this->logger->error('Dispatching SyncOrdersMessage failed for batch of size ' . $batchSize, [
                        'exception' => $e,
                        'event' => OrderEvents::ORDER_WRITTEN_EVENT,
                        'salesChannelId' => $salesChannelId,
                    ]);
                }
            }
        }
    }
}
