<?php declare(strict_types=1);

namespace Listrak\Tests\Integration;

use Listrak\Message\SubscribeNewsletterRecipientMessage;
use Listrak\Message\SubscribeNewsletterRecipientMessageHandler;
use Listrak\Message\SyncNewsletterRecipientsMessage;
use Listrak\Message\SyncNewsletterRecipientsMessageHandler;
use Listrak\Message\UnsubscribeNewsletterRecipientMessage;
use Listrak\Message\UnsubscribeNewsletterRecipientMessageHandler;
use Listrak\Service\ContactListService;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExportTest extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;

    public function testNewsletterUpdatesAndBulkExportWithoutCustomerAccounts(): void
    {
        $context = $this->context();
        $container = self::getContainer();
        self::assertSame(0, $container->get('customer.repository')->searchIds(new Criteria(), $context->getContext())->getTotal());
        $repository = $container->get('newsletter_recipient.repository');
        $salutation = Uuid::randomHex();
        $container->get('salutation.repository')->create([['id' => $salutation, 'salutationKey' => $salutation, 'displayName' => 'Mx.', 'letterName' => 'Dear']], $context->getContext());
        $container->get(SystemConfigService::class)->set('Listrak.config.salutationSegmentationFieldId', 42);
        $ids = [];
        foreach (['direct', 'optIn', 'notSet', 'optOut'] as $status) {
            $ids[$status] = Uuid::randomHex();
            $repository->create([[
                'id' => $ids[$status], 'hash' => Uuid::randomHex(), 'email' => $status . '@example.com', 'status' => $status,
                'salesChannelId' => $context->getSalesChannelId(), 'salutationId' => $salutation,
            ]], $context->getContext());
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $api = $this->createMock(ListrakApiService::class);
        $contacts = [];
        $api->expects(self::exactly(2))->method('createOrUpdateContact')->willReturnCallback(
            static function (array $data) use (&$contacts): void { $contacts[] = $data; }
        );
        $factory = $container->get(SalesChannelContextFactory::class);
        $mapper = $container->get(DataMappingService::class);
        (new SubscribeNewsletterRecipientMessageHandler($repository, $api, $mapper, $factory, $logger))(
            new SubscribeNewsletterRecipientMessage($ids['optIn'], $context->getSalesChannelId())
        );
        $unsubscribe = new UnsubscribeNewsletterRecipientMessageHandler($repository, $api, $factory, $logger);
        $unsubscribe(new UnsubscribeNewsletterRecipientMessage($ids['optOut'], $context->getSalesChannelId()));
        // A delayed unsubscribe must not undo a later confirmed subscription.
        $unsubscribe(new UnsubscribeNewsletterRecipientMessage($ids['optIn'], $context->getSalesChannelId()));
        self::assertSame('Subscribed', $contacts[0]['subscriptionState']);
        self::assertSame('Mx.', $contacts[0]['segmentationFieldValues'][0]['value']);
        self::assertSame('Unsubscribed', $contacts[1]['subscriptionState']);

        $api->expects(self::once())->method('startListImport')->with(self::callback(static function (array $data): bool {
            $csv = base64_decode($data['fileStream'], true);
            self::assertStringContainsString('direct@example.com,Mx.', $csv);
            self::assertStringContainsString('optIn@example.com,Mx.', $csv);
            self::assertStringNotContainsString('notSet@example.com', $csv);
            self::assertStringNotContainsString('optOut@example.com', $csv);
            return true;
        }), self::anything());
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        (new SyncNewsletterRecipientsMessageHandler($repository, $api, $mapper, $container->get(ContactListService::class), $factory, $bus, $logger))(
            new SyncNewsletterRecipientsMessage(0, 100, null, $context->getSalesChannelId())
        );
    }

    public function testProductFeedContainsCalculatedPricesAndMediaUrls(): void
    {
        $context = $this->context();
        $productId = Uuid::randomHex();
        $coverId = Uuid::randomHex();
        self::getContainer()->get('product.repository')->create([[
            'id' => $productId, 'productNumber' => 'LISTRAK-FEED', 'name' => 'Feed "quoted" product',
            'active' => true, 'stock' => 10,
            'taxId' => self::getContainer()->get('tax.repository')->searchIds(new Criteria(), $context->getContext())->firstId(),
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 10, 'linked' => true]],
            'visibilities' => [['salesChannelId' => $context->getSalesChannelId(), 'visibility' => 30]],
            'coverId' => $coverId,
            'media' => [['id' => $coverId, 'media' => ['fileName' => 'fixture', 'fileExtension' => 'png', 'mimeType' => 'image/png', 'path' => 'media/listrak-fixture.png']]],
        ]], $context->getContext());
        $file = self::getContainer()->get(DataMappingService::class)->mapProductData(1, $context);
        self::assertIsString($file);
        try {
            $stream = fopen($file, 'rb');
            $headers = fgetcsv($stream, 0, '|', '"', '');
            $values = fgetcsv($stream, 0, '|', '"', '');
            fclose($stream);
            $row = array_combine($headers, $values);
            self::assertSame('LISTRAK-FEED', $row['Sku']);
            self::assertSame('Feed "quoted" product', $row['Title']);
            self::assertGreaterThan(0, (float) $row['Price']);
            self::assertStringContainsString('/media/listrak-fixture.png', $row['ImageUrl']);
            self::assertStringStartsWith('http', $row['LinkUrl']);
            self::assertStringContainsString('LISTRAK-FEED', $row['LinkUrl']);
            self::assertSame('true', $row['IsPurchasable']);
        } finally {
            unlink($file);
        }
    }

    public function testCustomerExportReadsTheRealPartialAddressAssociations(): void
    {
        $context = $this->context();
        $container = self::getContainer();
        $ids = new \Shopware\Core\Test\Stub\Framework\IdsCollection();
        $customer = (new \Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder($ids, 'customer-export', $context->getSalesChannelId()))->build();
        $container->get('customer.repository')->create([$customer], $context->getContext());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $api = $this->createMock(ListrakApiService::class);
        $api->expects(self::once())->method('exportCustomer')->with(self::callback(static function (array $data): bool {
            self::assertCount(1, $data);
            self::assertSame('customer-export', $data[0]['customerNumber']);
            self::assertSame('33062', $data[0]['zipcode']);
            self::assertSame('Bielefeld', $data[0]['address']['city']);
            self::assertNotEmpty($data[0]['address']['country']);
            return true;
        }), self::anything());
        (new \Listrak\Message\SyncCustomersMessageHandler(
            $container->get('customer.repository'), $api, $container->get(DataMappingService::class),
            $container->get(SalesChannelContextFactory::class), $this->createMock(MessageBusInterface::class), $logger
        ))(new \Listrak\Message\SyncCustomersMessage(0, 100, [$customer['id']], null, $context->getSalesChannelId()));
    }

    public function testOrderBatchUsesEachOrdersStoredCurrencyAndLoadsLineItems(): void
    {
        $context = $this->context();
        $container = self::getContainer();
        $usd = $container->get('currency.repository')->searchIds((new Criteria())->addFilter(new EqualsFilter('isoCode', 'USD')), $context->getContext())->firstId();
        self::assertNotNull($usd);
        $productId = Uuid::randomHex();
        $container->get('product.repository')->create([[
            'id' => $productId, 'name' => 'Order fixture', 'productNumber' => 'ORDER-SKU', 'stock' => 10,
            'taxId' => $container->get('tax.repository')->searchIds(new Criteria(), $context->getContext())->firstId(),
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 50, 'net' => 50, 'linked' => true]],
        ]], $context->getContext());
        $orders = [];
        foreach ([['EUR-ORDER', Defaults::CURRENCY, 1.25], ['USD-ORDER', $usd, 1.7]] as [$number, $currency, $factor]) {
            $ids = new \Shopware\Core\Test\Stub\Framework\IdsCollection();
            $order = (new \Shopware\Core\Test\Integration\Builder\Order\OrderBuilder($ids, $number, $context->getSalesChannelId()))
                ->price(100)->shippingCosts(0)
                ->addAddress('billing_address', ['id' => $ids->get('billing_address'), 'countryId' => $context->getShippingLocation()->getCountry()->getId()])
                ->add('currencyId', $currency)->add('currencyFactor', $factor)
                ->add('orderCustomer', ['email' => 'order@example.com', 'firstName' => 'Order', 'lastName' => 'Fixture', 'customerNumber' => 'fixture'])
                ->add('lineItems', [[
                    'id' => Uuid::randomHex(), 'identifier' => 'fixture', 'type' => 'product', 'label' => 'Fixture', 'quantity' => 2,
                    'productId' => $productId, 'referencedId' => $productId,
                    'payload' => ['productNumber' => 'ORDER-SKU'],
                    'price' => new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(50, 100, new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(), new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection(), 2),
                ]])->build();
            $container->get('order.repository')->create([$order], $context->getContext());
            $orders[] = $order['id'];
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $api = $this->createMock(ListrakApiService::class);
        $usdFactor = $container->get('currency.repository')->search(new Criteria([$usd]), $context->getContext())->first()->getFactor();
        $api->expects(self::once())->method('exportOrder')->with(self::callback(static function (array $data) use ($usdFactor): bool {
            self::assertCount(2, $data);
            $byNumber = array_column($data, null, 'orderNumber');
            self::assertSame(100.0, $byNumber['USD-ORDER']['orderTotal']);
            self::assertSame(50.0, $byNumber['USD-ORDER']['items'][0]['price']);
            self::assertSame('ORDER-SKU', $byNumber['USD-ORDER']['items'][0]['sku']);
            self::assertSame(round(100 * $usdFactor / 1.25, 2), $byNumber['EUR-ORDER']['orderTotal']);
            self::assertNotEmpty($byNumber['EUR-ORDER']['billingAddress']['country']);
            return true;
        }), self::anything());
        (new \Listrak\Message\SyncOrdersMessageHandler(
            $container->get('order.repository'), $api, $container->get(DataMappingService::class),
            $container->get(SalesChannelContextFactory::class), $this->createMock(MessageBusInterface::class), $logger
        ))(new \Listrak\Message\SyncOrdersMessage(0, 100, $orders, null, $context->getSalesChannelId()));
    }

    public function testGuestCheckoutNewsletterUsesTheAuthenticatedCustomer(): void
    {
        $context = $this->context();
        $container = self::getContainer();
        $ids = new \Shopware\Core\Test\Stub\Framework\IdsCollection();
        $customer = (new \Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder($ids, 'guest-newsletter', $context->getSalesChannelId()))->add('guest', true)->build();
        $container->get('customer.repository')->create([$customer], $context->getContext());
        $context = $container->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), $context->getSalesChannelId(), ['customerId' => $customer['id']]);
        $config = $container->get(SystemConfigService::class);
        $config->set('core.newsletter.doubleOptIn', false);
        $config->set('core.newsletter.doubleOptInRegistered', false);
        $controller = $container->get(\Listrak\Controller\NewsletterController::class);
        foreach (['subscribe' => 'direct', 'unsubscribe' => 'optOut'] as $option => $status) {
            $request = \Symfony\Component\HttpFoundation\Request::create('/listrak/newsletter', 'POST', ['option' => $option]);
            $response = $controller->update($request, new \Shopware\Core\Framework\Validation\DataBag\RequestDataBag([
                'option' => $option, 'email' => 'someone-else@example.com',
            ]), $context);
            $messages = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('success', $messages[0]['type']);
            $recipients = $container->get('newsletter_recipient.repository')->search(new Criteria(), $context->getContext());
            self::assertCount(1, $recipients);
            self::assertSame($customer['email'], $recipients->first()->getEmail());
            self::assertSame($status, $recipients->first()->getStatus());
        }
        $route = $container->get('router')->getRouteCollection()->get('frontend.listrak.newsletter');
        self::assertSame(['POST'], $route->getMethods());
        self::assertTrue($route->getDefault('_loginRequired'));
        self::assertTrue($route->getDefault('_loginRequiredAllowGuest'));
    }

    public function testAnonymousVisitorCannotUseTheCheckoutNewsletterEndpoint(): void
    {
        $this->expectException(\Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException::class);
        self::getContainer()->get(\Listrak\Controller\NewsletterController::class)->update(
            new \Symfony\Component\HttpFoundation\Request(),
            new \Shopware\Core\Framework\Validation\DataBag\RequestDataBag(['option' => 'subscribe']),
            $this->context()
        );
    }

    private function context(): SalesChannelContext
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $id = self::getContainer()->get('sales_channel.repository')->searchIds($criteria, Context::createCLIContext())->firstId();
        self::assertNotNull($id);
        return self::getContainer()->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), $id);
    }
}
