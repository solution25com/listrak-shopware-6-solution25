<?php

declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ListrakConfigService
{
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function getConfig(string $configName): mixed
    {
        return $this->systemConfigService->get('Listrak.config.' . trim($configName));
    }

    public function setConfig(string $configName, mixed $value): void
    {
        $this->systemConfigService->set('Listrak.config.' . trim($configName), $value);
    }

    public function isDataSyncEnabled(string $configName): bool
    {
        return $this->getConfig($configName) && $this->getConfig('dataClientId') && $this->getConfig('dataClientSecret');
    }

    public function isEmailSyncEnabled(): bool
    {
        return $this->getConfig('enableNewsletterRecipientSync') && $this->getConfig('emailClientId') && $this->getConfig('emailClientSecret') && $this->getConfig('listId');
    }
}
