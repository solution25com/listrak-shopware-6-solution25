<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterUnsubscribeEvent;
use Shopware\Core\Content\Newsletter\NewsletterEvents;
use Shopware\Core\Content\Newsletter\SalesChannel\NewsletterUnsubscribeRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerSubscriber implements EventSubscriberInterface
{
    private ListrakConfigService $listrakConfigService;

    private ListrakApiService $listrakApiService;

    private LoggerInterface $logger;

    public function __construct(
        ListrakConfigService $listrakConfigService,
        ListrakApiService $listrakApiService,
        readonly EntityRepository $customerRepository,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfigService;
        $this->listrakApiService = $listrakApiService;
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

    public function onCustomerRegistered(CustomerRegisterEvent $event): void
    {
        if (!$this->listrakConfigService->isSyncEnabled('enableCustomerSync')) {
            return;
        }
        $this->logger->debug('Listrak customer written event triggered');

        $customer = $event->getCustomer();
        $address = $customer->getDefaultBillingAddress();

        $data = [
            'customerNumber' => $customer->getCustomerNumber(),
            'firstName' => $customer->getFirstName(),
            'lastName' => $customer->getLastName(),
            'email' => $customer->getEmail(),
            'address' => [
                'street' => $address ? $address->getStreet() : '',
                'city' => $address ? $address->getCity() : '',
                'state' => $address && $address->getCountryState() ? $address->getCountryState()->getName() : '',
                'postalCode' => $address ? $address->getZipcode() : '',
                'country' => $address && $address->getCountry() ? $address->getCountry()->getName() : '',
            ],
        ];
    }

    public function onCustomerWritten(EntityWrittenEvent $event): void
    {
        if (!$this->listrakConfigService->isSyncEnabled('enableCustomerSync')) {
            return;
        }
        $this->logger->debug('Listrak customer written event triggered');
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
            $address = $customer->getDefaultBillingAddress();
            $addressItem = [];
            if ($address) {
                $addressItem = [
                    'street' => $address->getStreet() ?? '',
                    'city' => $address->getCity() ?? '',
                    'state' => $address->getCountryState() ? $address->getCountryState()->getName() : '',
                    'postalCode' => $address->getZipcode() ?? '',
                    'country' => $address->getCountry() ? $address->getCountry()->getName() : '',
                ];
            }

            $data = [
                'customerNumber' => $customer->getCustomerNumber(),
                'firstName' => $customer->getFirstName(),
                'lastName' => $customer->getLastName(),
                'email' => $customer->getEmail(),
            ];
            $data['address'] = $addressItem;

            $items[] = $data;
        }
        $this->listrakApiService->importCustomer(
            $items,
            $event->getContext(),
        );
    }

    public function onNewsletterConfirm(NewsletterConfirmEvent $event): void
    {
        if (!$this->listrakConfigService->isSyncEnabled('enableCustomerSync')) {
            return;
        }
        $this->logger->debug('Listrak newsletter confirm event triggered');

        $newsletterRecipient = $event->getNewsletterRecipient();
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => 'Subscribed'
        ];
        $this->listrakApiService->createorUpdateContact($data, $event->getContext());
    }

    public function onNewsletterUnsubscribe(NewsletterUnsubscribeEvent $event): void
    {
        if (!$this->listrakConfigService->isSyncEnabled('enableCustomerSync')) {
            return;
        }
        $this->logger->debug('Listrak newsletter unsubscribe event triggered');

        $newsletterRecipient = $event->getNewsletterRecipient();
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => 'Unsubscribed'
        ];
        $this->listrakApiService->createorUpdateContact($data, $event->getContext());
    }
}
