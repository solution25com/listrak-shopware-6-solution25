<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;

class DataMappingService
{
    private $listrakApiService;

    private LoggerInterface $logger;

    public function __construct(
        ListrakApiService $listrakApiService,
        LoggerInterface $logger
    ) {
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    public function mapOrderData($order)
    {
        $orderState = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';
        $orderStatus = $this->mapOrderStatus($orderState);
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
        $items = $this->mapOrderLineItems($order, $orderStatus);

        $data = [
            'orderNumber' => $order->getOrderNumber(),
            'dateEntered' => '2025-04-22T01:39:35Z',
            'email' => $email,
            'customerNumber' => $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerNumber() : '',
            'billingAddress' => $billingAddressItem,
            'items' => $items[0],
            'itemTotal' => $items[1],
            'orderTotal' => $order->getPrice()->getTotalPrice(),
            'shipDate' => $firstDelivery ? $firstDelivery->getShippingDateEarliest()->format('Y-m-d\TH:i:s\Z') : '',
            'shippingAddress' => $shippingAddressItem,
            'shippingMethod' => $firstDelivery && $firstDelivery->getShippingMethod() ? $firstDelivery->getShippingMethod()->getName() : '',
            'shippingTotal' => $order->getShippingTotal(),
            'status' => $orderStatus,
            'taxTotal' => $order->getPrice()->getCalculatedTaxes()->getAmount(),
        ];
        return $data;
    }

    public function mapOrderLineItems($order, $orderStatus)
    {
        $lineItems = [];
        $orderItemTotal = 0;
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
            $lineItems[] = $item;
        }
        return [$lineItems, $orderItemTotal];
    }


    /**
     * @param string $status
     * @return string
     */
    public function mapOrderStatus(string $status): string
    {
        $sw_order_states = [
            'open' => 'Pending',
            'in_progress' => 'Processing',
            'completed' => 'Completed',
            'cancelled' => 'Canceled',
        ];
        if (array_key_exists($status, $sw_order_states)) {
            return $sw_order_states[$status];
        }

        return 'Unknown';
    }

    public function mapCustomerData($customer): array
    {
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
        return $data;
    }
}
