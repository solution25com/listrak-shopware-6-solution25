<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class DataMappingService
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly EntityRepository $currencyRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mapOrderData(OrderEntity $order, $context): array
    {
        $orderState = $order->getStateMachineState()->getTechnicalName();
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
        $items = $this->mapOrderLineItems($order, $orderStatus, $context);

        $data = [
            'orderNumber' => $order->getOrderNumber(),
            'dateEntered' => $order->getOrderDateTime()->format('Y-m-d\TH:i:s\Z'),
            'email' => $email,
            'customerNumber' => $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerNumber() : '',
            'billingAddress' => $billingAddressItem,
            'items' => $items[0],
            'itemTotal' => $items[1],
            'orderTotal' => $this->convertToUsd($order->getPrice()->getTotalPrice(), $order->getCurrencyId(), $order->getSalesChannelId(), $context),
            'shipDate' => $firstDelivery ? $firstDelivery->getShippingDateEarliest()->format('Y-m-d\TH:i:s\Z') : '',
            'shippingAddress' => $shippingAddressItem,
            'shippingMethod' => $firstDelivery && $firstDelivery->getShippingMethod() ? $firstDelivery->getShippingMethod()->getName() : '',
            'shippingTotal' => $this->convertToUsd($order->getShippingTotal(), $order->getCurrencyId(), $order->getSalesChannelId(), $context),
            'status' => $orderStatus,
            'taxTotal' => $this->convertToUsd($order->getPrice()->getCalculatedTaxes()->getAmount(), $order->getCurrencyId(), $order->getSalesChannelId(), $context),
        ];
        $this->logger->debug('Mapping order data', ['order' => $order]);

        return $data;
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
            $data['segmentationFieldValues'][] = [
                'segmentationFieldId' => $salutationListrakFieldId,
                'value' => $newsletterRecipient->getSalutation() ?? '',
            ];
        }

        if ($firstNameListrakFieldId) {
            $data['segmentationFieldValues'][] = [
                'segmentationFieldId' => $firstNameListrakFieldId,
                'value' => $newsletterRecipient->getFirstName() ?? '',
            ];
        }
        if ($lastNameListrakFieldId) {
            $data['segmentationFieldValues'][] = [
                'segmentationFieldId' => $lastNameListrakFieldId,
                'value' => $newsletterRecipient->getLastName() ?? '',
            ];
        }

        return $data;
    }

    public function mapListImportData(string $base64File): array
    {
        $fileMappings = $this->mapFileFields();
        $data = [
            'fileDelimiter' => ',',
            'fileMappings' => $fileMappings,
            'fileName' => 'listrak_list_import.csv',
            'fileStream' => $base64File,
            'hasColumnNames' => true,
            'importType' => 'AddSubscribersAndSegmentationData',
            'segmentationImportType' => 'Update',
            'suppressEmailNotifications' => true,
            'textQualifier' => '"',
            'triggerJourney' => false,
            'includeUnsubscribed' => false,
        ];

        return $data;
    }

    public function mapTransactionalMessageData($recipients, $fields): array
    {
        $data = [];

        foreach ($recipients as $recipientEmail => $recipientName) {
            $data[] = [
                'emailAddress' => $recipientEmail,
                'segmentationFieldValues' => $fields,
            ];
        }

        return $data;
    }

    public function mapTemplateVariables($data): array
    {
        $profileFields = [];
        foreach ($data as $key => $value) {
            $profileFields[] = ['segmentationFieldId' => $key, 'value' => $value];
        }

        return $profileFields;
    }

    public function convertToUsd(
        float $amount,
        string $fromCurrencyId,
        string $salesChannelId,
        Context $context
    ): float {
        // Short-circuit if already USD
        $fromCurrency = $this->currencyRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('id', $fromCurrencyId)),
            $context
        )->first();

        if (strtoupper($fromCurrency->getIsoCode()) === 'USD') {
            return $amount;
        }

        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('currency');
        $salesChannel = $this->salesChannelRepository
            ->search($criteria, $context)
            ->get($salesChannelId);

        $channelCurrency = $salesChannel?->getCurrency();

        $usdCurrency = $this->currencyRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('isoCode', 'USD')),
            $context
        )->first();

        if (!$channelCurrency || !$usdCurrency) {
            throw new \RuntimeException('Default or USD currency not found');
        }

        $amountInDefault = $fromCurrency->getId() === $channelCurrency->getId()
            ? $amount
            : $amount / $fromCurrency->getFactor();

        $amountInUsd = $amountInDefault * $usdCurrency->getFactor();

        return round($amountInUsd, 2);
    }

    private function mapOrderLineItems(OrderEntity $order, string $orderStatus, $context): array
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
                    'discountedPrice' => $this->convertToUsd($discountedPrice, $order->getCurrencyId(), $order->getSalesChannelId(), $context),
                    'itemTotal' => $this->convertToUsd($itemTotal, $order->getCurrencyId(), $order->getSalesChannelId(), $context),
                    'itemDiscountTotal' => $this->convertToUsd($itemDiscountTotal, $order->getCurrencyId(), $order->getSalesChannelId(), $context),
                    'orderNumber' => $order->getOrderNumber(),
                    'price' => $this->convertToUsd($unitPrice, $order->getCurrencyId(), $order->getSalesChannelId(), $context),
                    'quantity' => $quantity,
                    'sku' => $sku,
                    'status' => $orderStatus,
                ];
                $lineItems[] = $item;
            }
        }

        return [$lineItems, $orderItemTotal];
    }

    private function mapOrderStatus(string $status): string
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

    private function generateSKU(OrderLineItemEntity $lineItem): string
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
        $data = [
            'direct' => 'Subscribed',
            'unsubscribed' => 'Unsubscribed',
        ];
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

    private function mapFileFields()
    {
        $salutationListrakFieldId = $this->listrakConfigService->getConfig('salutationSegmentationFieldId');
        $firstNameListrakFieldId = $this->listrakConfigService->getConfig('firstNameSegmentationFieldId');
        $lastNameListrakFieldId = $this->listrakConfigService->getConfig('lastNameSegmentationFieldId');
        $data = [
            ['fileColumn' => 0, 'fileColumnType' => 'Email'],
        ];
        if ($salutationListrakFieldId) {
            $data[] = [
                'fileColumn' => 1,
                'fileColumnType' => 'SegmentationField',
                'segmentationFieldId' => $salutationListrakFieldId,
            ];
        }
        if ($firstNameListrakFieldId) {
            $data[] = [
                'fileColumn' => 2,
                'fileColumnType' => 'SegmentationField',
                'segmentationFieldId' => $firstNameListrakFieldId,
            ];
        }
        if ($lastNameListrakFieldId) {
            $data[] = [
                'fileColumn' => 3,
                'fileColumnType' => 'SegmentationField',
                'segmentationFieldId' => $lastNameListrakFieldId,
            ];
        }

        return $data;
    }
}
