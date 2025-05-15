<?php

declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;

class DataMappingService
{
    private ListrakConfigService $listrakConfigService;

    public function __construct(
        ListrakConfigService $listrakConfigService,
    ) {
        $this->listrakConfigService = $listrakConfigService;
    }

    public function mapOrderData(OrderEntity $order): array
    {
        $orderState = $order->getStateMachineState() ? $order->getStateMachineState()->getTechnicalName() : 'Unknown';
        $orderStatus = $this->mapOrderStatus($orderState);
        $customer = $order->getOrderCustomer();
        $email = $customer && $customer->getEmail() ? $customer->getEmail() : '';
        $billingAddress = $order->getBillingAddress();
        $billingAddressItem = $this->mapAddress($billingAddress);

        $deliveries = [];
        $firstDelivery = null;
        if ($order->getDeliveries()) {
            foreach ($order->getDeliveries() as $delivery) {
                $deliveries[] = $delivery;
            }
            $firstDelivery = $deliveries[0];
        }

        $shippingAddress = $firstDelivery ? $firstDelivery->getShippingOrderAddress() : null;
        $shippingAddressItem = $this->mapAddress($shippingAddress);
        $items = $this->mapOrderLineItems($order, $orderStatus);

        $data = [
            'orderNumber' => $order->getOrderNumber(),
            'dateEntered' => $order->getOrderDateTime()->format('Y-m-d\TH:i:s\Z'),
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

    public function mapOrderLineItems(OrderEntity $order, string $orderStatus): array
    {
        $lineItems = [];
        $orderItemTotal = 0;
        if ($order->getLineItems()) {
            foreach ($order->getLineItems() as $lineItem) {
                $calculatedPrice = $lineItem->getPrice();
                $sku = $this->generateSKU($lineItem);
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
        }

        return [$lineItems, $orderItemTotal];
    }

    public function mapOrderStatus(string $status): string
    {
        $sw_order_states = [
            'open' => 'Pending',
            'in_progress' => 'Processing',
            'completed' => 'Completed',
            'cancelled' => 'Canceled',
        ];
        if (\array_key_exists($status, $sw_order_states)) {
            return $sw_order_states[$status];
        }

        return 'Unknown';
    }

    public function mapCustomerData(CustomerEntity $customer): array
    {
        $address = $customer->getDefaultBillingAddress();
        $addressItem = [];
        if ($address) {
            $addressItem = [
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
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

    public function mapContactData(NewsletterRecipientEntity $newsletterRecipient): array
    {
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => $this->mapSubscriptionStatus($newsletterRecipient->getStatus()),
        ];

        $salutationListrakFieldId = $this->listrakConfigService->getConfig('salutationSegmentationFieldId') ?? '';
        $firstNameListrakFieldId = $this->listrakConfigService->getConfig('firstNameSegmentationFieldId') ?? '';
        $lastNameListrakFieldId = $this->listrakConfigService->getConfig('lastNameSegmentationFieldId') ?? '';
        if ($salutationListrakFieldId) {
            $data['segmentationFieldValues'] = [
                'segmentationFieldId' => $salutationListrakFieldId,
                'value' => $newsletterRecipient->getSalutation() ?? '',
            ];
        }

        if ($firstNameListrakFieldId) {
            $data['segmentationFieldValues'] = [
                'segmentationFieldId' => $firstNameListrakFieldId,
                'value' => $newsletterRecipient->getFirstName() ?? '',
            ];
        }
        if ($lastNameListrakFieldId) {
            $data['segmentationFieldValues'] = [
                'segmentationFieldId' => $lastNameListrakFieldId,
                'value' => $newsletterRecipient->getLastName() ?? '',
            ];
        }

        return $data;
    }

    public function generateSKU(OrderLineItemEntity $lineItem): string
    {
        switch ($lineItem->getType()) {
            case LineItem::PRODUCT_LINE_ITEM_TYPE:
                return $lineItem->getPayload()['productNumber'] ?? 'PRODUCT_ITEM_' . $lineItem->getId();
            case LineItem::CONTAINER_LINE_ITEM:
                return 'CONTAINER_ITEM_' . $lineItem->getId();
            case LineItem::DISCOUNT_LINE_ITEM:
                return 'DISCOUNT_ITEM_' . $lineItem->getId();
            case LineItem::PROMOTION_LINE_ITEM_TYPE:
                return 'PROMOTION_ITEM_' . $lineItem->getId();
            case LineItem::CREDIT_LINE_ITEM_TYPE:
                return 'CREDIT_ITEM_' . $lineItem->getId();
            default:
                return 'CUSTOM_ITEM_' . $lineItem->getId();
        }
    }

    private function mapSubscriptionStatus(?string $status): string
    {
        $data = ['direct' => 'Subscribed',
            'unsubscribed' => 'Unsubscribed'];
        if ($status !== null && \array_key_exists($status, $data)) {
            return $data[$status];
        }

        return 'Unsubscribed';
    }

    private function mapAddress(mixed $address): array
    {
        if ($address) {
            return [
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName(),
                'mobilePhone' => $address->getPhoneNumber() ?? '',
                'phone' => $address->getPhoneNumber() ?? '',
                'zipCode' => $address->getZipCode() ?? '',
                'city' => $address->getCity(),
                'country' => $address->getCountry() ? $address->getCountry()->getName() : '',
                'state' => $address->getCountryState() ? $address->getCountryState()->getName() : '',
                'address1' => $address->getStreet(),
                'address2' => $address->getAdditionalAddressLine1() ?? '',
                'address3' => $address->getAdditionalAddressLine2() ?? '',
            ];
        }

        return [];
    }
}
