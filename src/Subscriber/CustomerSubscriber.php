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
        /** @var list<string> $customerIds */
        $customerIds = $event->getIds();
        if ($customerIds === []) {
            return;
        }

        $criteria = (new Criteria($customerIds))->addFields(['id', 'salesChannelId']);
        $customers = $this->customerRepository->search($criteria, $event->getContext())->getEntities();

        /** @var array<string,bool> $enabledCache */
        $enabledCache = [];
        /** @var array<string,list<string>> $salesChannelCustomerMap */
        $salesChannelCustomerMap = [];

        /** @var PartialEntity $customer */
        foreach ($customers as $customer) {
            $cid = $customer->get('id');
            $sc = $customer->get('salesChannelId');

            if (!\is_string($cid) || $cid === '' || !\is_string($sc) || $sc === '') {
                continue;
            }

            if (!\array_key_exists($sc, $enabledCache)) {
                $enabledCache[$sc] = $this->listrakConfigService->isDataSyncEnabled('enableCustomerSync', $sc);
            }

            if (!$enabledCache[$sc]) {
                $this->logger->notice('Customer sync skipped — not enabled for sales channel', [
                    'customerId' => $cid,
                    'event' => CustomerEvents::CUSTOMER_WRITTEN_EVENT,
                    'salesChannelId' => $sc,
                ]);
                continue;
            }

            $salesChannelCustomerMap[$sc][] = $cid;
        }

        if ($salesChannelCustomerMap === []) {
            return;
        }

        $this->logger->debug('Customer written event triggered', [
            'count' => \count($customerIds),
        ]);

        $batchSize = 300;
        foreach ($salesChannelCustomerMap as $salesChannelId => $ids) {
            foreach (array_chunk($ids, $batchSize) as $chunk) {
                try {
                    $this->messageBus->dispatch(
                        new SyncCustomersMessage(0, $batchSize, $chunk, $chunk[0], $salesChannelId)
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Dispatching SyncCustomersMessage failed for batch of size ' . $batchSize,
                        [
                            'exception' => $e,
                            'event' => CustomerEvents::CUSTOMER_WRITTEN_EVENT,
                            'salesChannelId' => $salesChannelId,
                        ]
                    );
                }
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
            $this->logger->error('Newsletter sync failed', [
                'exception' => $e->getMessage(),
                'newsletterRecipientId' => $newsletterRecipientId,
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }

    public function onNewsletterUnsubscribe(NewsletterUnsubscribeEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();

        if (!$this->listrakConfigService->isEmailSyncEnabled($salesChannelId)) {
            $this->logger->debug('Newsletter sync skipped — sync not enabled for SalesChannel', [
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
            $this->logger->error('Newsletter sync failed', [
                'exception' => $e->getMessage(),
                'newsletterRecipientId' => $newsletterRecipientId,
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }
}
