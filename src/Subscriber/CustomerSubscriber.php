<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SubscribeNewsletterRecipientMessage;
use Listrak\Message\SyncCustomersMessage;
use Listrak\Message\UnsubscribeNewsletterRecipientMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterUnsubscribeEvent;
use Shopware\Core\Content\Newsletter\NewsletterEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class CustomerSubscriber implements EventSubscriberInterface
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
            CustomerEvents::CUSTOMER_WRITTEN_EVENT => 'onCustomerWritten',
            NewsletterEvents::NEWSLETTER_CONFIRM_EVENT => 'onNewsletterConfirm',
            NewsletterEvents::NEWSLETTER_UNSUBSCRIBE_EVENT => 'onNewsletterUnsubscribe',
        ];
    }

    public function onCustomerWritten(EntityWrittenEvent $event): void
    {
        if (!$this->listrakConfigService->isDataSyncEnabled('enableCustomerSync')) {
            $this->logger->debug('Customer sync skipped — sync not enabled');

            return;
        }

        $ids = [];
        foreach ($event->getWriteResults() as $writeResult) {
            $id = $writeResult->getPrimaryKey();
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE || !$id) {
                continue;
            }
            $ids[] = $id;
        }
        try {
            $message = new SyncCustomersMessage($event->getContext(), 0, 500, $ids);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    public function onNewsletterConfirm(NewsletterConfirmEvent $event): void
    {
        if (!$this->listrakConfigService->isEmailSyncEnabled()) {
            $this->logger->debug('Newsletter recipient sync skipped — sync not enabled for SalesChannel', [
                'salesChannelId' => $event->getSalesChannelId(),
            ]);

            return;
        }
        $this->logger->debug('Listrak newsletter confirm event triggered');

        $newsletterRecipientId = $event->getNewsletterRecipientId();

        try {
            $message = new SubscribeNewsletterRecipientMessage($event->getContext(), $newsletterRecipientId);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    public function onNewsletterUnsubscribe(NewsletterUnsubscribeEvent $event): void
    {
        if (!$this->listrakConfigService->isEmailSyncEnabled()) {
            $this->logger->debug('Newsletter recipient sync skipped — sync not enabled for SalesChannel', [
                'salesChannelId' => $event->getSalesChannelId(),
            ]);

            return;
        }
        $this->logger->debug('Listrak newsletter confirm event triggered');

        $newsletterRecipientId = $event->getNewsletterRecipientId();

        try {
            $message = new UnsubscribeNewsletterRecipientMessage($event->getContext(), $newsletterRecipientId);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    public function onCustomerRegisterEvent(CustomerRegisterEvent $event): void
    {
        if (!$this->listrakConfigService->isEmailSyncEnabled()) {
            $this->logger->debug('Newsletter recipient sync skipped — sync not enabled for SalesChannel', [
                'salesChannelId' => $event->getSalesChannelId(),
            ]);
        }
    }
}
