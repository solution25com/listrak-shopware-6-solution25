<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
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
        $orderIds = [];
        $orderIdToSalesChannel = [];

        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            $id = $writeResult->getPrimaryKey();
            if (!$id) {
                continue;
            }

            $orderIds[] = $id;

            $payload = $writeResult->getPayload();
            if (isset($payload['salesChannelId']) && $payload['salesChannelId']) {
                $orderIdToSalesChannel[$id] = $payload['salesChannelId'];
            }
        }

        $orderIds = array_values(array_unique($orderIds));
        if (!$orderIds) {
            return;
        }

        $missingIds = array_values(array_diff($orderIds, array_keys($orderIdToSalesChannel)));
        if ($missingIds) {
            $criteria = new Criteria($missingIds);
            $criteria->addFields(['id', 'salesChannelId']);

            $orders = $this->orderRepository->search($criteria, $event->getContext());

            /** @var PartialEntity $order */
            foreach ($orders as $order) {
                $orderIdToSalesChannel[$order['id']] = $order['salesChannelId'];
            }
        }

        $enabledCache = [];            // salesChannelId => bool.
        $salesChannelOrderMap = [];    // salesChannelId => orderId[].

        foreach ($orderIdToSalesChannel as $orderId => $salesChannelId) {
            if (!\array_key_exists($salesChannelId, $enabledCache)) {
                $enabledCache[$salesChannelId] = $this->listrakConfigService->isDataSyncEnabled(
                    'enableOrderSync',
                    $salesChannelId
                );
            }

            if (!$enabledCache[$salesChannelId]) {
                $this->logger->debug('Listrak order sync skipped — not enabled for sales channel', [
                    'orderId' => (string) $orderId,
                    'salesChannelId' => (string) $salesChannelId,
                ]);
                continue;
            }

            $salesChannelOrderMap[$salesChannelId][] = $orderId;
        }

        if (!$salesChannelOrderMap) {
            return;
        }

        $batchSize = 300;

        foreach ($salesChannelOrderMap as $salesChannelId => $ids) {
            foreach (array_chunk($ids, $batchSize) as $chunk) {
                try {
                    $message = new SyncOrdersMessage(0, $batchSize, $chunk, $chunk[0], $salesChannelId);
                    $this->messageBus->dispatch($message);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        \sprintf(
                            'Listrak order sync failed for sales channel %s: %s',
                            $salesChannelId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }
    }
}
