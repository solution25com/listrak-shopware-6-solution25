<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SubscribeNewsletterRecipientMessage;
use Listrak\Message\SyncCustomersMessage;
use Listrak\Message\UnsubscribeNewsletterRecipientMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterUnsubscribeEvent;
use Shopware\Core\Content\Newsletter\NewsletterEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class CustomerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityRepository $customerRepository,
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
        $customerIds = [];

        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            $id = $writeResult->getPrimaryKey();
            if ($id) {
                $customerIds[] = $id;
            }
        }

        if (empty($customerIds)) {
            return;
        }

        $criteria = new Criteria($customerIds);
        $customers = $this->customerRepository->search($criteria, $event->getContext());

        $salesChannelCustomerMap = [];

        /** @var CustomerEntity $customer */
        foreach ($customers as $customer) {
            $salesChannelId = $customer->getSalesChannelId();
            if (!$salesChannelId) {
                continue;
            }

            if (!$this->listrakConfigService->isDataSyncEnabled('enableCustomerSync', $salesChannelId)) {
                $this->logger->debug("Listrak customer sync skipped for ID {$customer->getId()} — sync not enabled for SalesChannel $salesChannelId");
                continue;
            }

            $salesChannelCustomerMap[$salesChannelId][] = $customer->getId();
        }

        foreach ($salesChannelCustomerMap as $salesChannelId => $customerIdsForChannel) {
            try {
                $message = new SyncCustomersMessage(0, 500, $customerIdsForChannel, $salesChannelId);
                $this->messageBus->dispatch($message);
            } catch (\Exception $e) {
                $this->logger->error("Listrak customer sync failed for sales channel $salesChannelId: " . $e->getMessage());
            }
        }
    }

    public function onNewsletterConfirm(NewsletterConfirmEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();

        if (!$this->listrakConfigService->isEmailSyncEnabled($salesChannelId)) {
            $this->logger->debug('Newsletter sync skipped — sync not enabled for SalesChannel', [
                'salesChannelId' => $salesChannelId,
            ]);

            return;
        }

        $this->logger->debug('Newsletter confirm event triggered');

        $newsletterRecipientId = $event->getNewsletterRecipientId();

        try {
            $message = new SubscribeNewsletterRecipientMessage($newsletterRecipientId, $salesChannelId);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $this->logger->error('Listrak newsletter sync failed: ' . $e->getMessage(), [
                'newsletterRecipientId' => $newsletterRecipientId,
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }

    public function onNewsletterUnsubscribe(NewsletterUnsubscribeEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();

        if (!$this->listrakConfigService->isEmailSyncEnabled($salesChannelId)) {
            $this->logger->debug('Listrak newsletter sync skipped — sync not enabled for SalesChannel', [
                'salesChannelId' => $salesChannelId,
            ]);

            return;
        }
        $this->logger->debug('Newsletter unsubscribe event triggered');

        $newsletterRecipientId = $event->getNewsletterRecipientId();

        try {
            $message = new UnsubscribeNewsletterRecipientMessage($newsletterRecipientId, $salesChannelId);
            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            $this->logger->error('Listrak newsletter sync failed: ' . $e->getMessage(), [
                'newsletterRecipientId' => $newsletterRecipientId,
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }
}
