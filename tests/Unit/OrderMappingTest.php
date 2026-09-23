<?php declare(strict_types=1);

namespace Listrak\Tests\Unit;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class OrderMappingTest extends TestCase
{
    public function testUsdOrderUsesItsOwnCurrencyEvenWhenTheChannelDefaultIsEuro(): void
    {
        $euro = new CurrencyEntity();
        $euro->setIsoCode('EUR');
        $euro->setFactor(1);
        $usd = new CurrencyEntity();
        $usd->setIsoCode('USD');
        $usd->setFactor(1.5);
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($euro);
        $currencies = $this->createMock(EntityRepository::class);
        $currencies->expects(self::never())->method('search');
        $mapper = new DataMappingService($this->createMock(SalesChannelRepository::class), $this->createMock(EntityRepository::class), $currencies, $this->createMock(ListrakConfigService::class), new NullLogger());
        $order = new PartialEntity();
        $order->assign([
            'orderNumber' => 'USD-ORDER', 'currency' => $usd, 'currencyFactor' => 1.2,
            'price' => new CartPrice(120, 120, 120, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_FREE),
            'shippingTotal' => 5.0, 'orderDateTime' => new \DateTimeImmutable('2026-09-23T12:00:00+02:00'),
        ]);
        $data = $mapper->mapOrderData($order, $context);
        self::assertSame(120.0, $data['orderTotal']);
        self::assertSame(5.0, $data['shippingTotal']);
        self::assertSame('2026-09-23T10:00:00Z', $data['dateEntered']);
    }
}
