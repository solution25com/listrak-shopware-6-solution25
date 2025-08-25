<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SyncOrdersMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
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
            CheckoutOrderPlacedEvent::class => 'onCheckoutOrderPlaced',
        ];
    }

    public function onCheckoutOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();

        if (!$this->listrakConfigService->isDataSyncEnabled('enableOrderSync', $salesChannelId)) {
            $this->logger->debug('Order sync skipped — sync not enabled for SalesChannel', [
                'salesChannelId' => $salesChannelId,
            ]);

            return;
        }
        $this->logger->debug('Checkout order placed event triggered');

        $order = $event->getOrder();


        try {
            $this->messageBus->dispatch(new SyncOrdersMessage(0, 300, [$order->getId()], $order->getId(), $salesChannelId));
        } catch (\Exception $e) {
            $this->logger->error('Order sync failed', [
                'exception' => $e->getMessage(),
                'orderId' => $order->getId(),
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }
}
