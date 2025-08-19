<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DataMappingService
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $currencyRepository,
        private readonly ListrakConfigService $listrakConfigService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function mapOrderData($order, $salesChannelContext): array
    {
        $orderState = $order['stateMachineState']['technicalName'] ?? 'Unknown';
        $orderStatus = $this->mapOrderStatus($orderState);
        $customer = $order['orderCustomer'];
        $email = $customer['email'] ?? '';
        $billingAddress = $order['billingAddress'] ?? '';
        $billingAddressItem = $this->mapAddress($billingAddress);

        $items = $this->mapOrderLineItems($order, $salesChannelContext);

        return [
            'orderNumber' => $order['orderNumber'],
            'dateEntered' => $order['orderDateTime']?->format('Y-m-d\TH:i:s\Z'),
            'email' => $email,
            'customerNumber' => $order['orderCustomer']['customerNumber'] ?? '',
            'billingAddress' => $billingAddressItem,
            'items' => $items[0],
            'itemTotal' => $items[1],
            'orderTotal' => $this->convertToUsd($order['price']->getTotalPrice(), $salesChannelContext),
            'shippingTotal' => $this->convertToUsd($order['shippingTotal'], $salesChannelContext),
            'status' => $orderStatus,
            'taxTotal' => $this->convertToUsd($order['price']->getCalculatedTaxes()->getAmount(), $salesChannelContext),
        ];
    }

    public function mapCustomerData($customer): array
    {
        $address = $customer['activeBillingAddress'] ?? $customer['defaultBillingAddress'];

        $data = [
            'customerNumber' => $customer['customerNumber'],
            'firstName' => $customer['firstName'],
            'lastName' => $customer['lastName'],
            'email' => $customer['email'],
            'birthday' => $customer['birthday']?->format('Y-m-d') ?? '',
            'registered' => !$customer['guest'],
            'customerGroup' => $customer['group']['translated']['name'] ?? $customer['group']['name'] ?? '',
            'zipCode' => $address['zipcode'] ?? '',
        ];

        if ($address) {
            $data['address'] = $this->mapAddress($address);
        }

        return $data;
    }

    public function mapContactData(
        Entity $newsletterRecipient,
        ?string $salesChannelId = null
    ): array {
        $data = [
            'emailAddress' => $newsletterRecipient['email'],
            'subscriptionState' => $this->mapSubscriptionStatus($newsletterRecipient['status']),
        ];

        $salutationListrakFieldId = $this->listrakConfigService->getConfig(
            'salutationSegmentationFieldId',
            $salesChannelId
        ) ?? '';
        $firstNameListrakFieldId = $this->listrakConfigService->getConfig(
            'firstNameSegmentationFieldId',
            $salesChannelId
        ) ?? '';
        $lastNameListrakFieldId = $this->listrakConfigService->getConfig(
            'lastNameSegmentationFieldId',
            $salesChannelId
        ) ?? '';
        if ($salutationListrakFieldId) {
            $data['segmentationFieldValues'][] = [
                'segmentationFieldId' => $salutationListrakFieldId,
                'value' => $newsletterRecipient['salutation'] ?? '',
            ];
        }

        if ($firstNameListrakFieldId) {
            $data['segmentationFieldValues'][] = [
                'segmentationFieldId' => $firstNameListrakFieldId,
                'value' => $newsletterRecipient['firstName'] ?? '',
            ];
        }
        if ($lastNameListrakFieldId) {
            $data['segmentationFieldValues'][] = [
                'segmentationFieldId' => $lastNameListrakFieldId,
                'value' => $newsletterRecipient['lastName'] ?? '',
            ];
        }

        return $data;
    }

    public function mapListImportData(string $base64File, ?string $salesChannelId = null): array
    {
        $fileMappings = $this->mapFileFields($salesChannelId);
        $formattedDate = (new \DateTimeImmutable())->format('YmdHis');
        $fileName = 'Listrak_Contact_Import_' . $formattedDate . '.csv';

        return [
            'fileDelimiter' => ',',
            'fileMappings' => $fileMappings,
            'fileName' => $fileName,
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
        $tmp = tempnam(sys_get_temp_dir(), 'listrak_product_export_' . $salesChannelContext->getSalesChannelId());
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
            $criteria->addFilter(
                new EqualsFilter('visibilities.salesChannelId', $salesChannelContext->getSalesChannelId())
            );
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addAssociation('seoUrls');
            $criteria->addAssociation('cover');
            $criteria->addAssociation('manufacturer');
            $criteria->addAssociation('visibilities');

            $criteria->addFields([
                'id',
                'productNumber',
                'name',
                'description',
                'availableStock',
                'price',
                'parentId',
                'categoryTree',
                'seoUrls.id', 'seoUrls.seoPathInfo', 'seoUrls.pathInfo',
                'seoUrls.isCanonical', 'seoUrls.languageId', 'seoUrls.salesChannelId',
                'seoUrls.routeName', 'seoUrls.isDeleted',
                'cover.id',
                'cover.media.id',
                'cover.media.path',
                'cover.media.fileName',
                'cover.media.fileExtension',
                'cover.media.private',
                'manufacturer.id',
                'manufacturer.name',
                'visibilities.id',
                'visibilities.salesChannelId',
            ]);

            $searchResult = $this->productRepository->search($criteria, $salesChannelContext->getContext());
            $products = $searchResult->getEntities();
            $this->logger->debug(
                'Products found for Listrak sync in sales channel: ',
                ['productCount' => \count($products), 'salesChannelId' => $salesChannelContext->getSalesChannelId()]
            );
            /** @var PartialEntity $product */
            foreach ($products as $product) {
                ++$productCount;

                $url = $this->getFullProductUrl($product, $salesChannelContext);

                $names = $this->getCategoryNamesFromTree($product, $salesChannelContext->getContext());
                [$parent, $sub1, $sub2, $sub3] = array_pad($names, 4, null);
                $priceCollection = $product['price'];
                $gross = 0;

                if ($priceCollection !== null) {
                    $price = $priceCollection->first();
                    if ($price !== null) {
                        $gross = $price->getGross();
                    }
                }

                fputcsv($fh, [
                    $product['productNumber'],
                    $product['parentId'] ? 'V' : 'M',
                    $product['translated']['name'] ?? $product['name'] ?? '',
                    $product['cover']['media']['url'] ?? '',
                    $url,
                    $product['translated']['description'] ?? $product['description'] ?? '',
                    $this->convertToUsd($gross, $salesChannelContext),
                    $this->convertToUsd($gross, $salesChannelContext),
                    $product['manufacturer']['translated']['name'] ?? $product['manufacturer']['name'] ?? '',
                    $parent,
                    $sub1,
                    $sub2,
                    $sub3,
                    implode(' > ', $names),
                    $product['availableStock'],
                    $product['availableStock'] > 0 ? 'true' : 'false',
                    $product['availableStock'] > 0 ? 'true' : 'false',
                    '',
                    $product['productNumber'],
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
        $criteria = new Criteria([$salesChannelContext->getCurrencyId()]);
        $criteria->addFields(['isoCode', 'factor']);
        $criteria->setLimit(1);

        $fromCurrency = $this->currencyRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->first();

        if (strtoupper($fromCurrency['isoCode']) === 'USD') {
            return $amount;
        }
        $channelCurrency = $salesChannelContext->getCurrency();
        $usdCriteria = new Criteria();
        $usdCriteria->addFilter(new EqualsFilter('isoCode', 'USD'));
        $usdCriteria->addFields(['isoCode', 'factor']);
        $usdCurrency = $this->currencyRepository->search($usdCriteria, $salesChannelContext->getContext())->first();
        /** @var CurrencyEntity $fromCurrency */
        $amountInDefault = $fromCurrency->getId() === $channelCurrency->getId()
            ? $amount
            : $amount /
            $fromCurrency['factor'];

        /** @var PartialEntity $usdCurrency */
        $amountInUsd = $amountInDefault * $usdCurrency['factor'];

        return round($amountInUsd, 2);
    }

    private function getFullProductUrl(PartialEntity $product, SalesChannelContext $ctx): ?string
    {
        $scId = $ctx->getSalesChannelId();
        $langId = $ctx->getLanguageId();

        $seo = $this->pickCanonicalSeo($product['seoUrls'], $scId, $langId);

        if (!$seo && $product['parentId']) {
            $c = new Criteria([$product['parentId']]);
            $c->addAssociation('seoUrls');
            $c->addFields([
                'id',
                'seoUrls.id', 'seoUrls.seoPathInfo', 'seoUrls.pathInfo',
                'seoUrls.isCanonical', 'seoUrls.languageId', 'seoUrls.salesChannelId',
                'seoUrls.routeName', 'seoUrls.isDeleted',
            ]);
            $parent = $this->productRepository->search($c, $ctx->getContext())->first();
            if ($parent) {
                $seo = $this->pickCanonicalSeo($parent['seoUrls'], $scId, $langId);
            }
        }

        $path = $seo
            ? ltrim($seo['seoPathInfo'] ?: $seo['pathInfo'] ?: '', '/')
            : 'detail/' . $product->getId();

        $domains = $ctx->getSalesChannel()->getDomains();
        $domain = $seo
            ? ($domains->filter(fn ($d) => $d->getLanguageId() === $seo['languageId'])->first()
                ?? $domains->filter(fn ($d) => $d->getLanguageId() === $langId)->first()
                ?? $domains->first())
            : ($domains->filter(fn ($d) => $d->getLanguageId() === $langId)->first()
                ?? $domains->first());

        if (!$domain) {
            return null;
        }

        return rtrim($domain->getUrl(), '/') . '/' . $path;
    }

    private function pickCanonicalSeo(?iterable $collection, string $scId, string $preferredLangId): ?PartialEntity
    {
        if (!$collection) {
            return null;
        }

        $anyLang = null;
        foreach ($collection as $u) {
            if (!$u instanceof PartialEntity) {
                continue;
            }
            if ($u['isDeleted']) {
                continue;
            }
            if ($u['salesChannelId'] !== $scId) {
                continue;
            }
            if ($u['routeName'] !== 'frontend.detail.page') {
                continue;
            }
            if (!$u['isCanonical']) {
                continue;
            }

            if ($u['languageId'] === $preferredLangId) {
                return $u;
            }
            $anyLang ??= $u;
        }

        return $anyLang;
    }

    private function mapOrderLineItems(PartialEntity $order, $salesChannelContext): array
    {
        $lineItems = [];
        $orderItemTotal = 0;
        if ($order['lineItems']) {
            foreach ($order['lineItems'] as $lineItem) {
                $sku = $this->generateSKU($lineItem);
                $unitPrice = $this->convertToUsd($lineItem->getUnitPrice(), $salesChannelContext);
                $quantity = $lineItem->getQuantity();
                $itemTotal = $this->convertToUsd($lineItem->getTotalPrice(), $salesChannelContext);
                $orderItemTotal += $itemTotal;
                $item = [
                    'itemTotal' => $itemTotal,
                    'orderNumber' => $order['orderNumber'],
                    'price' => $unitPrice,
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
                'firstName' => $address['firstName'],
                'lastName' => $address['lastName'],
                'mobilePhone' => $address['phoneNumber'] ?? '',
                'phone' => $address['phoneNumber'] ?? '',
                'zipCode' => $address['zipcode'] ?? '',
                'city' => $address['city'] ?? '',
                'country' => $address['country']['translated']['name'] ?? $address['country']['name'] ?? '',
                'state' => $address['countryState']['translated']['name'] ?? $address['countryState']['name'] ?? '',
                'address1' => $address['street'],
                'address2' => $address['additionalAddressLine1'] ?? '',
                'address3' => $address['additionalAddressLine2'] ?? '',
            ];
        }

        return [];
    }

    private function mapFileFields(?string $salesChannelId = null)
    {
        $salutationListrakFieldId = $this->listrakConfigService->getConfig(
            'salutationSegmentationFieldId',
            $salesChannelId
        );
        $firstNameListrakFieldId = $this->listrakConfigService->getConfig(
            'firstNameSegmentationFieldId',
            $salesChannelId
        );
        $lastNameListrakFieldId = $this->listrakConfigService->getConfig(
            'lastNameSegmentationFieldId',
            $salesChannelId
        );
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
        PartialEntity $product,
        Context $context
    ): array {
        $ids = array_values(array_unique(array_filter($product['categoryTree'] ?? [])));
        if (!$ids) {
            return [];
        }

        $criteria = new Criteria($ids);
        $criteria->addFields(['id', 'name']);
        $result = $this->categoryRepository->search($criteria, $context)->getEntities();

        $nameById = [];
        /** @var PartialEntity $c */
        foreach ($result as $c) {
            $nameById[$c->getId()] = (string) ($c['translated']['name'] ?? $c['name'] ?? '');
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
