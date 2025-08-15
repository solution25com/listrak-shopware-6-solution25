<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Message\SubscribeNewsletterRecipientMessage;
use Listrak\Message\SyncCustomersMessage;
use Listrak\Message\UnsubscribeNewsletterRecipientMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterUnsubscribeEvent;
use Shopware\Core\Content\Newsletter\NewsletterEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
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

        $customerIds = array_values(array_unique($customerIds));
        if (!$customerIds) {
            return;
        }
        $criteria = new Criteria($customerIds);
        $criteria->addFields(['id', 'salesChannelId']);

        $customers = $this->customerRepository->search($criteria, $event->getContext());

        $salesChannelCustomerMap = [];
        $enabledCache = []; // salesChannelId => bool.

        /** @var PartialEntity $customer */
        foreach ($customers as $customer) {
            $salesChannelId = $customer['salesChannelId'];
            if (!$salesChannelId) {
                continue;
            }

            if (!\array_key_exists($salesChannelId, $enabledCache)) {
                $enabledCache[$salesChannelId] = $this->listrakConfigService
                    ->isDataSyncEnabled('enableCustomerSync', $salesChannelId);
            }

            if (!$enabledCache[$salesChannelId]) {
                $this->logger->debug('Listrak customer sync skipped — not enabled for sales channel', [
                    'customerId' => (string) $customer['id'],
                    'salesChannelId' => (string) $salesChannelId,
                ]);

                continue;
            }

            $salesChannelCustomerMap[$salesChannelId][] = $customer->getId();
        }

        $batchSize = 300;
        foreach ($salesChannelCustomerMap as $salesChannelId => $ids) {
            foreach (array_chunk($ids, $batchSize) as $chunk) {
                try {
                    $message = new SyncCustomersMessage(0, $batchSize, $chunk, $chunk[0], $salesChannelId);
                    $this->messageBus->dispatch($message);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        \sprintf(
                            'Listrak customer sync failed for sales channel %s: %s',
                            $salesChannelId,
                            $e->getMessage()
                        )
                    );
                }
            }
        }
    }

    public function onNewsletterConfirm(NewsletterConfirmEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();

        if (!$this->listrakConfigService->isEmailSyncEnabled($salesChannelId)) {
            $this->logger->debug('Newsletter sync skipped — sync not enabled for sales channel', [
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
