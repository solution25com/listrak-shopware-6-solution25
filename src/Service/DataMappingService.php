<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\ListPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\SalesChannelRepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DataMappingService
{
    public function __construct(
        private readonly SalesChannelRepository $productRepository,
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
            'zipcode' => $address['zipcode'] ?? '',
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

    /**
     * @throws \DateMalformedStringException
     */
    public function mapProductData($limit, SalesChannelContext $salesChannelContext): bool|string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'listrak_product_export_' . $salesChannelContext->getSalesChannelId());
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            $this->logger->error('Failed to create temp file', ['tmp' => $tmp, 'salesChannelId' => $salesChannelContext->getSalesChannelId()]);

            return false;
        }

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addFilter(
            new EqualsFilter('visibilities.salesChannelId', $salesChannelContext->getSalesChannelId())
        );
        $criteria->addSorting(new FieldSorting('id'));
        $criteria->addAssociation('seoUrls');
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('visibilities');
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);

        $criteria->addFields([
            'id',
            'productNumber',
            'childCount',
            'active',
            'available',
            'isCloseout',
            'minPurchase',
            'releaseDate',
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
            'calculatedPrice',
        ]);

        $iterator = new SalesChannelRepositoryIterator($this->productRepository, $salesChannelContext, $criteria);
        $productCount = 0;
        $wroteHeader = false;

        while ($result = $iterator->fetch()) {
            $entities = $result->getEntities();
            if ($entities->count() === 0) {
                break;
            }
            $headers = [
                'Sku',
                'Variant',
                'Title',
                'ImageUrl',
                'LinkUrl',
                'Description',
                'Price',
                'SalePrice',
                'Brand',
                'Category',
                'SubCategory',
                'SubCategory2',
                'SubCategory3',
                'CategoryTree',
                'QOH',
                'InStock',
                'OnSale',
                'IsPurchasable',
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

            if (!$wroteHeader) {
                fputcsv($fh, $headers, '|');
                $wroteHeader = true;
            }
            $parentIds = [];
            foreach ($entities as $p) {
                $pid = $p->get('parentId');
                if ($pid) {
                    $parentIds[$pid] = true;
                }
            }
            $parentIdList = array_keys($parentIds);

            $parentSkuById = [];
            if (!empty($parentIdList)) {
                $parentCrit = new Criteria($parentIdList);
                $parentCrit->addFields(['id', 'productNumber']);
                $parents = $this->productRepository->search($parentCrit, $salesChannelContext)->getEntities();
                foreach ($parents as $parent) {
                    $parentSkuById[$parent->get('id')] = $parent->get('productNumber');
                }
            }
            $this->logger->debug('Product: ', [$entities->first()]);
            /** @var PartialEntity $product */
            foreach ($entities as $product) {
                $url = $this->getFullProductUrl($product, $salesChannelContext);
                $parentId = $product['parentId'] ?? null;
                $parentSku = $parentId ? ($parentSkuById[$parentId] ?? '') : '';
                $names = $this->getCategoryNamesFromTree($product, $salesChannelContext->getContext());
                [$parent, $sub1, $sub2, $sub3] = array_pad($names, 4, null);
                [$unit, $list, $onSale] = $this->extractPricesFromProduct($product);
                $isPurchasable = $this->isPurchasable($product, $unit);
                $imageUrl = '';
                if (isset($product['cover']['media'])) {
                    $media = $product['cover']['media'];
                    if ($media instanceof MediaEntity) {
                        $imageUrl = $media->getUrl();
                    }
                    if ($media instanceof PartialEntity) {
                        $imageUrl = $media['url'] ?? '';
                    }
                }
                $row = [
                    $product['productNumber'],
                    $product['parentId'] ? 'V' : 'M',
                    $product['translated']['name'] ?? $product['name'] ?? '',
                    $imageUrl,
                    $url,
                    $product['translated']['description'] ?? $product['description'] ?? '',
                    $onSale ? $this->convertToUsd($list, $salesChannelContext) : $this->convertToUsd($unit, $salesChannelContext),
                    $onSale ? $this->convertToUsd($unit, $salesChannelContext) : '',
                    $product['manufacturer']['translated']['name'] ?? $product['manufacturer']['name'] ?? '',
                    $parent,
                    $sub1,
                    $sub2,
                    $sub3,
                    implode(' > ', $names),
                    $product['availableStock'],
                    $product['availableStock'] > 0 ? 'true' : 'false',
                    $onSale ? 'true' : 'false',
                    $isPurchasable ? 'true' : 'false',
                    $parentSku,
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
                    '',
                ];

                fputcsv($fh, $row, '|');
                ++$productCount;
            }
        }

        fclose($fh);

        $this->logger->debug('Products found for synchronization', [
            'productCount' => $productCount,
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
        ]);

        if ($productCount === 0) {
            @unlink($tmp);

            return false;
        }

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

    public function convertToUsd(float $amount, SalesChannelContext $ctx): float
    {
        $from = $ctx->getCurrency();

        if (strtoupper($from->getIsoCode()) === 'USD') {
            return $amount;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('isoCode', 'USD'))
            ->setLimit(1);

        /** @var CurrencyEntity|null $usd */
        $usd = $this->currencyRepository->search($criteria, $ctx->getContext())->first();

        if (!$usd) {
            throw new \RuntimeException('USD currency not found in system currencies.');
        }

        $rate = $usd->getFactor() / $from->getFactor();
        $usdAmount = $amount * $rate;

        return round($usdAmount, 2);
    }

    /**
     * @return array{0: float, 1: float|null, 2: bool} [unit, list, onSale]
     */
    public function extractPricesFromProduct(PartialEntity $product): array
    {
        $calc = $product->get('calculatedPrice');

        if ($calc instanceof CalculatedPrice) {
            $unit = (float) $calc->getUnitPrice();
            $lp = $calc->getListPrice();
            $list = $lp ? (float) $lp->getPrice() : null;

            return [
                $unit,
                $list,
                $list !== null && ($list - $unit) > 0.00001,
            ];
        }

        if (\is_array($calc)) {
            $unit = isset($calc['unitPrice']) ? (float) $calc['unitPrice'] : 0.0;
            $list = null;

            if (\array_key_exists('listPrice', $calc)) {
                $raw = $calc['listPrice'];
                if ($raw instanceof ListPrice) {
                    $list = $raw->getPrice();
                } elseif (\is_array($raw) && isset($raw['price'])) {
                    $list = (float) $raw['price'];
                }
            }

            return [
                $unit,
                $list,
                $list !== null && ($list - $unit) > 0.00001,
            ];
        }

        return [0.0, null, false];
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function isPurchasable(PartialEntity $p, $unitPrice): bool
    {
        if ($p->get('parentId') === null && (int) ($p->get('childCount') ?? 0) > 0) {
            return false;
        }

        if (!$p->get('active')) {
            return false;
        }

        if ($p->has('available')) {
            if ($p->get('available') !== true) {
                return false;
            }
        } else {
            $min = (int) ($p->get('minPurchase') ?? 1);
            $stock = (int) ($p->get('availableStock') ?? 0);
            $close = (bool) ($p->get('isCloseout') ?? false);
            if ($close && $stock < $min) {
                return false;
            }
            $release = $p->get('releaseDate');
            if ($release && new \DateTimeImmutable($release) > new \DateTimeImmutable()) {
                return false;
            }
        }

        return $unitPrice > 0.0;
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
            $parent = $this->productRepository->search($c, $ctx)->first();
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
            $country = $address['country'];
            $countryState = $address['countryState'];
            $countryName = '';
            $countryStateName = '';
            if ($country instanceof CountryEntity) {
                $countryName = $country->getTranslation('name') ?? $country->getName();
            }
            if ($country instanceof PartialEntity) {
                $countryName = $country['translated']['name'] ?? $country['name'] ?? '';
            }
            if ($countryState instanceof CountryStateEntity) {
                $countryStateName = $countryState->getTranslation('name') ?? $countryState->getName();
            }
            if ($countryState instanceof PartialEntity) {
                $countryStateName = $countryState['translated']['name'] ?? $countryState['name'] ?? '';
            }

            return [
                'firstName' => $address['firstName'],
                'lastName' => $address['lastName'],
                'mobilePhone' => $address['phoneNumber'] ?? '',
                'phone' => $address['phoneNumber'] ?? '',
                'zipcode' => $address['zipcode'] ?? '',
                'city' => $address['city'] ?? '',
                'country' => $countryName,
                'state' => $countryStateName,
                'address1' => $address['street'],
                'address2' => $address['additionalAddressLine1'] ?? '',
                'address3' => $address['additionalAddressLine2'] ?? '',
            ];
        }

        return [];
    }

    private function mapFileFields(?string $salesChannelId = null): array
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
