<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerSubscriber implements EventSubscriberInterface
{
    private ListrakConfigService $listrakConfigService;

    private ListrakApiService $listrakApiService;

    private LoggerInterface $logger;

    public function __construct(
        ListrakConfigService $listrakConfigService,
        ListrakApiService $listrakApiService,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfigService;
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerRegisterEvent::class => 'onCustomerRegistered',
        ];
    }

    public function onCustomerRegistered(CustomerRegisterEvent $event): void
    {
        if (!$this->listrakConfigService->isSyncEnabled('enableCustomerSync')) {
            return;
        }

        $this->logger->debug('Listrak customer register event triggered');

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

        $this->listrakApiService->importCustomer(
            $data,
            $event->getContext(),
        );
    }
}
