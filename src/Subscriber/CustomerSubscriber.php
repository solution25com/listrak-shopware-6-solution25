<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
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
        $this->logger->notice('Listrak customer written event triggered');
        $items = [];
        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() == EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            $payload = $writeResult->getPayload();
            $customerId = $payload['id'];

            $customer = $this->customerRepository->search(
                new Criteria([$customerId]),
                $event->getContext()
            )->first();

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
        $this->logger->notice('Listrak newsletter confirm event triggered');

        $newsletterRecipient = $event->getNewsletterRecipient();
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => 'Subscribed',
        ];
        $this->listrakApiService->createOrUpdateContact($data, $event->getContext());
    }

    public function onNewsletterUnsubscribe(NewsletterUnsubscribeEvent $event): void
    {
        $this->logger->notice('Listrak newsletter confirm event triggered');

        $newsletterRecipient = $event->getNewsletterRecipient();
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => 'Unsubscribed'
        ];
        $this->listrakApiService->createOrUpdateContact($data, $event->getContext());
    }
}
