<?php

declare(strict_types=1);

namespace Listrak\Tests\Integration;

use Doctrine\DBAL\Connection;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Migration\Migration1790146800ScopeFailedRequests;
use Listrak\ScheduledTask\RequestRetryTaskHandler;
use Listrak\Service\FailedRequestService;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakFTPService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twig\Environment;

final class CompatibilityTest extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;

    public function testShopwareDiscoversTheBuiltAdministrationEntryPoint(): void
    {
        $accessor = new \Shopware\Administration\Framework\Twig\ViteFileAccessorDecorator(
            [], new \Symfony\Component\Asset\Package(new \Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy()),
            self::getKernel(), new \Symfony\Component\Filesystem\Filesystem()
        );
        $bundle = self::getKernel()->getBundle('Listrak');
        $data = $accessor->getBundleData($bundle);
        self::assertNotEmpty($data['entryPoints']['listrak']['js']);
        self::assertNotEmpty($data['entryPoints']['listrak']['css']);
        foreach (['js', 'css'] as $type) {
            foreach ($data['entryPoints']['listrak'][$type] as $asset) {
                self::assertFileExists($bundle->getPath() . '/Resources/public/administration/assets/' . basename($asset));
            }
        }
    }

    public function testScheduledTaskAndFtpServiceCanBeCreatedByTheShopwareContainer(): void
    {
        self::assertInstanceOf(RequestRetryTaskHandler::class, self::getContainer()->get(RequestRetryTaskHandler::class));
        $ftp = self::getContainer()->get(ListrakFTPService::class);
        $file = tempnam(sys_get_temp_dir(), 'listrak-test-');
        file_put_contents($file, 'fixture');
        $key = 'compatibility-' . Uuid::randomHex() . '.txt';
        self::assertTrue($ftp->generateLocalFile($key, $file));
        $filesystem = self::getContainer()->get('shopware.filesystem.private');
        try {
            self::assertSame('fixture', $filesystem->read('listrak/' . $key));
            self::assertFileDoesNotExist($file);
        } finally {
            $filesystem->delete('listrak/' . $key);
        }
    }

    public function testQueuePersistsChannelAndIntegrationAndReplaysOnlyThatChannel(): void
    {
        $context = $this->salesChannelContext();
        $otherChannel = Uuid::randomHex();
        $repository = self::getContainer()->get('listrak_failed_requests.repository');
        $api = $this->createMock(ListrakApiService::class);
        $queue = new FailedRequestService($repository, $api, new NullLogger());
        $queue->saveRequestToFailedRequests('https://api.listrak.com/data/v1/Customer/', 'POST', [
            'body' => '{"customers":[]}', 'headers' => ['Authorization' => 'Bearer must-not-persist', 'Content-Type' => 'application/json'],
        ], 'HTTP 503', $context, 'DATA');
        $queue->flushFailedRequests($context);
        $rows = $repository->search(new Criteria(), $context->getContext())->getEntities();
        self::assertCount(1, $rows);
        $entry = $rows->first();
        self::assertInstanceOf(FailedRequestEntity::class, $entry);
        self::assertSame($context->getSalesChannelId(), $entry->getSalesChannelId());
        self::assertSame('DATA', $entry->getIntegrationType());
        self::assertArrayNotHasKey('Authorization', $entry->getOptions()['headers']);
        $repository->create([[
            'id' => Uuid::randomHex(), 'method' => 'POST', 'endpoint' => 'https://api.listrak.com/email/v1/List/',
            'response' => 'HTTP 503', 'options' => [], 'retryCount' => 1,
            'salesChannelId' => $otherChannel, 'integrationType' => 'EMAIL',
        ]], $context->getContext());
        $api->expects(self::once())->method('authorizedRequest')->with(
            ['url' => $entry->getEndpoint(), 'method' => 'POST'], $entry->getOptions(), $context, 'DATA',
            self::callback(static fn (FailedRequestEntity $value): bool => $value->getId() === $entry->getId())
        );
        $queue->retry($context);
    }

    public function testLegacyMigrationIsRepeatableAndDoesNotGuessSalesChannel(): void
    {
        $repository = self::getContainer()->get('listrak_failed_requests.repository');
        $id = Uuid::randomHex();
        $repository->create([[
            'id' => $id, 'method' => 'POST', 'endpoint' => 'https://api.listrak.com/data/v1/Order/',
            'response' => 'old response', 'retryCount' => 1,
            'options' => ['body' => '{"orderNumber":"fixture"}', 'headers' => ['Authorization' => 'Bearer legacy-secret'], 'form_params' => ['client_secret' => 'legacy-secret']],
        ]], Context::createCLIContext());
        $migration = new Migration1790146800ScopeFailedRequests();
        $migration->update(self::getContainer()->get(Connection::class));
        $migration->update(self::getContainer()->get(Connection::class));
        $entry = $repository->search(new Criteria([$id]), Context::createCLIContext())->getEntities()->first();
        self::assertNull($entry->getSalesChannelId());
        self::assertSame(3, $entry->getRetryCount());
        self::assertSame('{"orderNumber":"fixture"}', $entry->getOptions()['body']);
        self::assertStringNotContainsString('legacy-secret', json_encode($entry->getOptions(), JSON_THROW_ON_ERROR));
    }

    public function testFlowActionRendersProfileFieldsAndAcceptsEmptyProfileConfiguration(): void
    {
        $context = $this->salesChannelContext();
        $flow = new \Shopware\Core\Content\Flow\Dispatching\StorableFlow('fixture', $context->getContext(), [], [
            'salesChannelId' => $context->getSalesChannelId(),
            'mailStruct' => new \Shopware\Core\Framework\Event\EventData\MailRecipientStruct(['alice@example.com' => 'Alice']),
            'customer' => ['firstName' => 'Alice'],
        ]);
        $api = $this->createMock(ListrakApiService::class);
        $sent = [];
        $api->expects(self::exactly(2))->method('sendTransactionalMessage')->willReturnCallback(
            static function (string|int $id, array $data, SalesChannelContext $channel) use (&$sent, $context): void {
                self::assertSame(123, $id);
                self::assertSame($context->getSalesChannelId(), $channel->getSalesChannelId());
                $sent[] = $data;
            }
        );
        $action = new \Listrak\Core\Content\Flow\Dispatching\Action\ListrakSendMailAction(
            self::getContainer()->get(Connection::class), $api,
            self::getContainer()->get(\Listrak\Service\DataMappingService::class),
            self::getContainer()->get(\Shopware\Core\Framework\Adapter\Twig\StringTemplateRenderer::class),
            self::getContainer()->get('event_dispatcher'), self::getContainer()->get(SalesChannelContextFactory::class)
        );
        $config = ['transactionalMessageId' => 123, 'recipient' => ['type' => 'default']];
        $flow->setConfig($config + ['profileFields' => [42 => '{{ customer.firstName }}']]);
        $action->handleFlow($flow);
        self::assertSame([['segmentationFieldId' => 42, 'value' => 'Alice']], $sent[0][0]['segmentationFieldValues']);
        $flow->setConfig($config);
        $action->handleFlow($flow);
        self::assertSame([], $sent[1][0]['segmentationFieldValues']);
    }

    public function testCartAndOrderMarkupCarryConsentAndCorrectRoutePaths(): void
    {
        $context = $this->salesChannelContext();
        $config = self::getContainer()->get(SystemConfigService::class);
        $config->set('Listrak.config.merchantId', 'fixture');
        $config->set('Listrak.config.enableListrakTracking', true);
        $config->set('Listrak.config.enableListrakTrackingUponCookieAcceptance', true);
        $twig = self::getContainer()->get(Environment::class);
        foreach (['cart', 'order'] as $placement) {
            $html = $twig->render('@Listrak/storefront/component/cart-tracker.html.twig', [
                'context' => $context, 'placement' => $placement, 'page' => ['order' => [
                    'orderNumber' => 'fixture', 'lineItems' => [], 'deliveries' => [],
                    'currency' => ['isoCode' => 'USD'], 'currencyFactor' => 1.25,
                    'price' => ['totalPrice' => 0, 'calculatedTaxes' => []],
                ]],
            ]);
            self::assertMatchesRegularExpression('/data-listrak-tracking-options="([^"]+)"/', $html);
            preg_match('/data-listrak-tracking-options="([^"]+)"/', $html, $matches);
            $options = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($options['requiresCookieConsent']);
            self::assertSame($placement, $options['data']['placement']);
            self::assertStringEndsWith('/checkout/cart.json', $options['cartUrl']);
            self::assertStringEndsWith('/listrak/product-url', $options['productUrl']);
            if ($placement === 'order') {
                self::assertSame('USD', $options['data']['currencyIsoCode']);
                self::assertSame(1.25, $options['data']['currencyFactor']);
            }
        }
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $id = self::getContainer()->get('sales_channel.repository')->searchIds(new Criteria(), Context::createCLIContext())->firstId();
        self::assertNotNull($id);

        return self::getContainer()->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), $id);
    }
}
