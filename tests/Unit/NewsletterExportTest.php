<?php

declare(strict_types=1);

namespace Listrak\Tests\Unit;

use Listrak\Service\ContactListService;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class NewsletterExportTest extends TestCase
{
    public function testConfirmedOptInAndSalutationAreMappedToContactValues(): void
    {
        $config = $this->createMock(ListrakConfigService::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key): mixed => $key === 'salutationSegmentationFieldId' ? 42 : null);
        $mapper = new DataMappingService($this->createMock(SalesChannelRepository::class), $this->createMock(EntityRepository::class), $this->createMock(EntityRepository::class), $config, new NullLogger());
        $salutation = new \Shopware\Core\System\Salutation\SalutationEntity();
        $salutation->setDisplayName('Ms.');
        $recipient = new \Shopware\Core\Framework\DataAbstractionLayer\PartialEntity();
        $recipient->assign(['email' => 'alice@example.com', 'status' => 'optIn', 'salutation' => $salutation]);

        $data = $mapper->mapContactData($recipient, 'fixture');
        self::assertSame('Subscribed', $data['subscriptionState']);
        self::assertSame('Ms.', $data['segmentationFieldValues'][0]['value']);
        foreach (['optOut', 'notSet', null] as $status) {
            $recipient->assign(['status' => $status]);
            self::assertSame('Unsubscribed', $mapper->mapContactData($recipient)['subscriptionState']);
        }
    }

    public function testSelectedProfileColumnsContainValuesAndMatchTheirImportPositions(): void
    {
        $config = $this->createMock(ListrakConfigService::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key): mixed => $key === 'lastNameSegmentationFieldId' ? 42 : null);
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('fixture');
        $service = new ContactListService($config, new NullLogger());
        $csv = base64_decode($service->saveToCsv([['email' => 'alice@example.com', 'lastName' => 'Example']], $context), true);
        self::assertSame("email,\"Last Name\"\nalice@example.com,Example\n", $csv);
        $mapper = new DataMappingService($this->createMock(SalesChannelRepository::class), $this->createMock(EntityRepository::class), $this->createMock(EntityRepository::class), $config, new NullLogger());
        $import = $mapper->mapListImportData(base64_encode($csv), 'fixture');
        self::assertSame(1, $import['fileMappings'][1]['fileColumn']);
        self::assertSame(42, $import['fileMappings'][1]['segmentationFieldId']);
        self::assertSame('', $service->saveToCsv([], $context));
    }
}
