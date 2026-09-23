<?php

declare(strict_types=1);

namespace Listrak\Tests\Unit;

use Listrak\Service\ListrakConfigService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class ListrakConfigServiceTest extends TestCase
{
    public function testDataCredentialsEnableDataSyncWithoutEmailCredentials(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key): mixed => [
            'Listrak.config.enableCustomerSync' => true,
            'Listrak.config.dataClientId' => 'data-id',
            'Listrak.config.dataClientSecret' => 'data-secret',
        ][$key] ?? null);
        self::assertTrue((new ListrakConfigService($config))->isDataSyncEnabled('enableCustomerSync', 'channel'));
    }

    public function testEmailCredentialsCannotEnableDataSync(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn (string $key): mixed => [
            'Listrak.config.enableCustomerSync' => true,
            'Listrak.config.emailClientId' => 'email-id',
            'Listrak.config.emailClientSecret' => 'email-secret',
        ][$key] ?? null);
        self::assertFalse((new ListrakConfigService($config))->isDataSyncEnabled('enableCustomerSync', 'channel'));
    }
}
