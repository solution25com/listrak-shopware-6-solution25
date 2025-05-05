<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface
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
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        if (!$this->listrakConfigService->isSyncEnabled('enableOrderSync')) {
            return;
        }

        $this->logger->debug('Listrak order placed event triggered');

        $order = $event->getOrder();
        $orderState = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';
        $orderStatus = $this->listrakApiService->mapOrderStatus($orderState);
        $customer = $order->getOrderCustomer();
        $email = $customer && $customer->getEmail() ? $customer->getEmail() : '';
        $billingAddress = $order->getBillingAddress();
        $billingAddressItem = [];
        if ($billingAddress) {
            $billingAddressItem = [
                'firstName' => $billingAddress->getFirstName(),
                'lastName' => $billingAddress->getLastName(),
                'mobilePhone' => $billingAddress->getPhoneNumber() ?? '',
                'phone' => $billingAddress->getPhoneNumber() ?? '',
                'zipCode' => $billingAddress->getZipCode() ?? '',
                'city' => $billingAddress->getCity(),
                'country' => $billingAddress->getCountry() ? $billingAddress->getCountry()->getName() : '',
                'state' => $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getName() : '',
                'address1' => $billingAddress->getStreet(),
                'address2' => $billingAddress->getAdditionalAddressLine1() ?? '',
                'address3' => $billingAddress->getAdditionalAddressLine2() ?? '',
            ];
        }

        $items = [];
        $deliveries = [];
        $firstDelivery = null;
        if ($order->getDeliveries()) {
            foreach ($order->getDeliveries() as $delivery) {
                $deliveries[] = $delivery;
            }
            $firstDelivery = $deliveries[0];
        }

        $shippingAddress = $firstDelivery ? $firstDelivery->getShippingOrderAddress() : null;
        $shippingAddressItem = [];
        if ($shippingAddress) {
            $shippingAddressItem = [
                'firstName' => $shippingAddress->getFirstName(),
                'lastName' => $shippingAddress->getLastName(),
                'mobilePhone' => $shippingAddress->getPhoneNumber() ?? '',
                'phone' => $shippingAddress->getPhoneNumber() ?? '',
                'zipCode' => $shippingAddress->getZipCode() ?? '',
                'city' => $shippingAddress->getCity(),
                'country' => $shippingAddress->getCountry() ? $shippingAddress->getCountry()->getName() : '',
                'state' => $shippingAddress->getCountryState() ? $shippingAddress->getCountryState()->getName() : '',
                'address1' => $shippingAddress->getStreet(),
                'address2' => $shippingAddress->getAdditionalAddressLine1() ?? '',
                'address3' => $shippingAddress->getAdditionalAddressLine2() ?? ''
            ];
        }

        $orderItemTotal = 0;
        if ($order->getLineItems()) {
            foreach ($order->getLineItems() as $lineItem) {
                $calculatedPrice = $lineItem->getPrice();
                $sku = $this->listrakApiService->generateSku($lineItem);
                $listPrice = $calculatedPrice && $calculatedPrice->getListPrice() ? $calculatedPrice->getListPrice()->getPrice() : 0;
                $unitPrice = $lineItem->getUnitPrice();
                $discountedPrice = $calculatedPrice && $calculatedPrice->getListPrice() ? $calculatedPrice->getListPrice()->getDiscount() : 0;
                $quantity = $lineItem->getQuantity();
                $itemDiscountTotal = ($listPrice - $discountedPrice) * $lineItem->getQuantity();
                $itemTotal = ($unitPrice * $quantity) - $itemDiscountTotal;
                $orderItemTotal += $itemTotal;
                $item = [
                    'discountedPrice' => $discountedPrice,
                    'itemTotal' => $itemTotal,
                    'itemDiscountTotal' => $itemDiscountTotal,
                    'orderNumber' => $order->getOrderNumber(),
                    'price' => $unitPrice,
                    'quantity' => $quantity,
                    'sku' => $sku,
                    'status' => $orderStatus,
                ];
                $items[] = $item;
            }
        }

        $data = [[
            'orderNumber' => $order->getOrderNumber(),
            'dateEntered' => '2025-04-22T01:39:35Z',
            'email' => $email,
            'customerNumber' => $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerNumber() : '',
            'billingAddress' => $billingAddressItem,
            'items' => $items,
            'itemTotal' => $orderItemTotal,
            'orderTotal' => $order->getPrice()->getTotalPrice(),
            'shipDate' => $firstDelivery ? $firstDelivery->getShippingDateEarliest()->format('Y-m-d\TH:i:s\Z') : '',
            'shippingAddress' => $shippingAddressItem,
            'shippingMethod' => $firstDelivery && $firstDelivery->getShippingMethod() ? $firstDelivery->getShippingMethod()->getName() : '',
            'shippingTotal' => $order->getShippingTotal(),
            'status' => $orderStatus,
            'taxTotal' => $order->getPrice()->getCalculatedTaxes()->getAmount(),
        ]];

        $this->listrakApiService->importOrder($data, $event->getContext());
    }
}
