<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterUnsubscribeEvent;
use Shopware\Core\Content\Newsletter\NewsletterEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerSubscriber implements EventSubscriberInterface
{
    private ListrakConfigService $listrakConfigService;

    private ListrakApiService $listrakApiService;

    private DataMappingService $dataMappingService;

    private LoggerInterface $logger;

    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        ListrakConfigService $listrakConfigService,
        ListrakApiService $listrakApiService,
        DataMappingService $dataMappingService,
        readonly EntityRepository $customerRepository,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfigService;
        $this->listrakApiService = $listrakApiService;
        $this->dataMappingService = $dataMappingService;
        $this->logger = $logger;
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
            return;
        }
        $this->logger->debug('Listrak customer written event triggered');
        $ids = [];
        $items = [];

        foreach ($event->getWriteResults() as $writeResult) {
            $id = $writeResult->getPrimaryKey();
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE || !$id) {
                continue;
            }
            $ids[] = $id;
        }

        $customers = $this->customerRepository->search(
            new Criteria($ids),
            $event->getContext()
        )->getEntities();

        foreach ($customers as $customer) {
            $item = $this->dataMappingService->mapCustomerData($customer);
            $items[] = $item;
        }

        $this->listrakApiService->importCustomer(
            $items,
            $event->getContext(),
        );
    }

    public function onNewsletterConfirm(NewsletterConfirmEvent $event): void
    {
        if (!$this->listrakConfigService->isEmailSyncEnabled()) {
            return;
        }
        $this->logger->debug('Listrak newsletter confirm event triggered');

        $newsletterRecipient = $event->getNewsletterRecipient();
        $data = $this->dataMappingService->mapContactData($newsletterRecipient);

        $this->listrakApiService->createorUpdateContact($data, $event->getContext());
    }

    public function onNewsletterUnsubscribe(NewsletterUnsubscribeEvent $event): void
    {
        if (!$this->listrakConfigService->isEmailSyncEnabled()) {
            return;
        }
        $this->logger->notice('Listrak newsletter confirm event triggered');

        $newsletterRecipient = $event->getNewsletterRecipient();
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => 'Unsubscribed',
        ];

        $this->listrakApiService->createorUpdateContact($data, $event->getContext());
    }
}
