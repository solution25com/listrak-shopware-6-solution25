<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class DataMappingService
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $currencyRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly ListrakConfigService $listrakConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mapOrderData(OrderEntity $order, $salesChannelContext): array
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
        $items = $this->mapOrderLineItems($order, $salesChannelContext);

        return [
            'orderNumber' => $order->getOrderNumber(),
            'dateEntered' => $order->getOrderDateTime()->format('Y-m-d\TH:i:s\Z'),
            'email' => $email,
            'customerNumber' => $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomerNumber() : '',
            'billingAddress' => $billingAddressItem,
            'items' => $items[0],
            'itemTotal' => $items[1],
            'orderTotal' => $this->convertToUsd($order->getPrice()->getTotalPrice(), $salesChannelContext),
            'shippingAddress' => $shippingAddressItem,
            'shippingTotal' => $this->convertToUsd($order->getShippingTotal(), $salesChannelContext),
            'status' => $orderStatus,
            'taxTotal' => $this->convertToUsd($order->getPrice()->getCalculatedTaxes()->getAmount(), $salesChannelContext),
        ];
    }

    public function mapCustomerData(CustomerEntity $customer): array
    {
        $address = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();

        $data = [
            'customerNumber' => $customer->getCustomerNumber(),
            'firstName' => $customer->getFirstName(),
            'lastName' => $customer->getLastName(),
            'email' => $customer->getEmail(),
            'birthday' => $customer->getBirthday()?->format('Y-m-d'),
            'registered' => !$customer->getGuest(),
            'customerGroup' => $customer->getGroup()->getName(),
            'zipCode' => $address?->getZipCode(),
        ];

        if ($address) {
            $data['address'] = $this->mapAddress($address);
        }

        return $data;
    }

    public function mapContactData(NewsletterRecipientEntity $newsletterRecipient, ?string $salesChannelId = null): array
    {
        $data = [
            'emailAddress' => $newsletterRecipient->getEmail(),
            'subscriptionState' => $this->mapSubscriptionStatus($newsletterRecipient->getStatus()),
        ];

        $salutationListrakFieldId = $this->listrakConfigService->getConfig('salutationSegmentationFieldId', $salesChannelId) ?? '';
        $firstNameListrakFieldId = $this->listrakConfigService->getConfig('firstNameSegmentationFieldId', $salesChannelId) ?? '';
        $lastNameListrakFieldId = $this->listrakConfigService->getConfig('lastNameSegmentationFieldId', $salesChannelId) ?? '';
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

    public function mapListImportData(string $base64File, ?string $salesChannelId = null): array
    {
        $fileMappings = $this->mapFileFields($salesChannelId);

        return [
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
    }

    public function mapProductData($offset, $limit, SalesChannelContext $salesChannelContext): bool|string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'prod_export_' . $salesChannelContext->getSalesChannelId()) . '.txt';
        $fh = fopen($tmp, 'w');

        $headers = [
            'Sku',
            'Variant',
            'Title',
            'ImageUrl',
            'LinkUrl',
            'Description',
            'Price',
            'SalesPrice',
            'Brand',
            'Category',
            'SubCategory',
            'SubCategory2',
            'SubCategory3',
            'CategoryTree',
            'QOH',
            'InStock',
            'SystemInStock',
            'MasterSku',
            'ReviewProductID',
            'Related_Sku_1',
            'Related_Type_1',
            'Related_Rank_1',
            'Related_Sku_2',
            'Related_Type_2',
            'Related_Rank_2',
            'Related_Sku_3',
            'Related_Type_3',
            'Related_Rank_3',
            'Related_Sku_4',
            'Related_Type_4',
            'Related_Rank_4',
            'Related_Sku_5',
            'Related_Type_5',
            'Related_Rank_5',
        ];
        fputcsv($fh, $headers, '|');
        $productCount = 0;
        do {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addFilter(new EqualsFilter('visibilities.salesChannelId', $salesChannelContext->getSalesChannelId()));
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addAssociation('seoUrls');
            $criteria->addAssociation('cover.media');
            $criteria->addAssociation('manufacturer');
            $criteria->addAssociation('mainCategories.category');
            $criteria->addAssociation('visibilities');

            $searchResult = $this->productRepository->search($criteria, $salesChannelContext->getContext());
            $products = $searchResult->getEntities();
            $this->logger->debug('Products found for Listrak sync in sales channel: ', ['productCount' => \count($products), 'salesChannelId' => $salesChannelContext->getSalesChannelId()]);
            /** @var ProductEntity $product */
            foreach ($products as $product) {
                ++$productCount;
                $url = $this->getFullProductUrl($product, $salesChannelContext);
                $names = $this->getCategoryNamesFromTree($product, $salesChannelContext->getContext());
                [$parent, $sub1, $sub2, $sub3] = array_pad($names, 4, null);
                $priceCollection = $product->getPrice();
                $gross = 0;

                if ($priceCollection !== null) {
                    $price = $priceCollection->first();
                    if ($price !== null) {
                        $gross = $price->getGross();
                    }
                }

                fputcsv($fh, [
                    $product->getProductNumber(),
                    $product->getParentId() ? 'V' : 'M',
                    $product->getTranslation('name'),
                    $product->getCover()?->getMedia()?->getUrl() ?? '',
                    $url,
                    $product->getTranslation('description'),
                    $this->convertToUsd($gross, $salesChannelContext),
                    $this->convertToUsd($gross, $salesChannelContext),
                    $product->getManufacturer()->getName() ?? '',
                    $parent,
                    $sub1,
                    $sub2,
                    $sub3,
                    implode(' > ', $names),
                    $product->getAvailableStock(),
                    $product->getAvailableStock() > 0,
                    $product->getAvailableStock() > 0,
                    '',
                    $product->getProductNumber(),
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ], '|');
            }
            $offset += $limit;
        } while ($products->count() > 0);

        if ($productCount === 0) {
            return false;
        }

        fclose($fh);

        return $tmp;
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
        SalesChannelContext $salesChannelContext
    ): float {
        // Short-circuit if already USD.
        /** @var CurrencyEntity $fromCurrency */
        $fromCurrency = $this->currencyRepository->search(
            new Criteria([$salesChannelContext->getCurrencyId()]),
            $salesChannelContext->getContext()
        )->first();

        if (strtoupper($fromCurrency->getIsoCode()) === 'USD') {
            $this->logger->debug('no need to convert');

            return $amount;
        }
        $criteria = new Criteria([$salesChannelContext->getSalesChannelId()]);
        $criteria->addAssociation('currency');

        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository
            ->search($criteria, $salesChannelContext->getContext())
            ->get($salesChannelContext->getSalesChannelId());

        $channelCurrency = $salesChannel->getCurrency();

        $usdCurrency = $this->currencyRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('isoCode', 'USD')),
            $salesChannelContext->getContext()
        )->first();

        if (!$channelCurrency || !$usdCurrency) {
            throw new \RuntimeException('Default or USD currency not found');
        }

        $amountInDefault = $fromCurrency->getId() === $channelCurrency->getId()
            ? $amount
            : $amount /
            $fromCurrency->getFactor();

        /** @var CurrencyEntity $usdCurrency */
        $amountInUsd = $amountInDefault * $usdCurrency->getFactor();

        return round($amountInUsd, 2);
    }

    public function getFullProductUrl(
        ProductEntity $product,
        SalesChannelContext $context,
    ): ?string {
        $seo = $product->getSeoUrls()?->filter(
            fn (SeoUrlEntity $u) => $u->getIsCanonical() && $u->getSalesChannelId() === $context->getSalesChannelId()
        )->first();

        if (!$seo && $product->getParent()) {
            $seo = $product->getParent()->getSeoUrls()?->filter(
                fn (SeoUrlEntity $u) => $u->getIsCanonical() && $u->getSalesChannelId() === $context->getSalesChannelId()
            )->first();
        }
        if (!$seo) {
            return null;
        }

        $domains = $context->getSalesChannel()->getDomains();
        $domain = $domains->filter(fn ($d) => $d->getLanguageId() === $seo->getLanguageId())->first()
            ?? $domains->filter(fn ($d) => $d->getLanguageId() === $context->getLanguageId())->first()
            ?? $domains->first();

        if (!$domain) {
            return null;
        }

        $base = rtrim($domain->getUrl(), '/');
        $path = ltrim($seo->getSeoPathInfo(), '/');

        return $path ? $base . '/' . $path : $base;
    }

    private function mapOrderLineItems(OrderEntity $order, $salesChannelContext): array
    {
        $lineItems = [];
        $orderItemTotal = 0;
        if ($order->getLineItems()) {
            foreach ($order->getLineItems() as $lineItem) {
                $this->logger->info('Order LineItem', ['lineItem' => $lineItem]);
                $sku = $this->generateSKU($lineItem);
                $unitPrice = $lineItem->getUnitPrice();
                $quantity = $lineItem->getQuantity();
                $itemTotal = $lineItem->getTotalPrice();
                $orderItemTotal += $itemTotal;
                $item = [
                    'itemTotal' => $this->convertToUsd($itemTotal, $salesChannelContext),
                    'orderNumber' => $order->getOrderNumber(),
                    'price' => $this->convertToUsd($unitPrice, $salesChannelContext),
                    'quantity' => $quantity,
                    'sku' => $sku,
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
                'country' => $address->getCountry() ? $address->getCountry()->getTranslation('name') : '',
                'state' => $address->getCountryState() ? $address->getCountryState()->getTranslation('name') : '',
                'address1' => $address->getStreet(),
                'address2' => $address->getAdditionalAddressLine1() ?? '',
                'address3' => $address->getAdditionalAddressLine2() ?? '',
            ];
        }

        return [];
    }

    private function mapFileFields(?string $salesChannelId = null)
    {
        $salutationListrakFieldId = $this->listrakConfigService->getConfig('salutationSegmentationFieldId', $salesChannelId);
        $firstNameListrakFieldId = $this->listrakConfigService->getConfig('firstNameSegmentationFieldId', $salesChannelId);
        $lastNameListrakFieldId = $this->listrakConfigService->getConfig('lastNameSegmentationFieldId', $salesChannelId);
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

    private function getCategoryNamesFromTree(
        ProductEntity $product,
        Context $context
    ): array {
        $ids = array_values(array_unique(array_filter($product->getCategoryTree() ?? [])));
        if (!$ids) {
            return [];
        }

        $criteria = new Criteria($ids);
        $result = $this->categoryRepository->search($criteria, $context)->getEntities();

        $nameById = [];
        /** @var CategoryEntity $c */
        foreach ($result as $c) {
            $nameById[$c->getId()] = (string) ($c->getTranslation('name') ?? $c->getName() ?? '');
        }

        // Preserve original order from categoryTree.
        $names = [];
        foreach ($ids as $id) {
            if (isset($nameById[$id]) && $nameById[$id] !== '') {
                $names[] = $nameById[$id];
            }
        }

        return $names;
    }
}
