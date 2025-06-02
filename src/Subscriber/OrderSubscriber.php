<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly MessageBusInterface $messageBus,
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
        if (!$this->listrakConfigService->isDataSyncEnabled('enableOrderSync')) {
            $this->logger->debug('Order sync skipped — sync not enabled');

            return;
        }
        $this->logger->debug('Listrak order written event triggered');
        $ids = [];
        $items = [];

        foreach ($event->getWriteResults() as $writeResult) {
            $id = $writeResult->getPrimaryKey();
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE && !$id) {
                continue;
            }
            $ids[] = $id;
        }
        try {
            $message = new SyncOrdersMessage($event->getContext(), 0, 500, $ids);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
