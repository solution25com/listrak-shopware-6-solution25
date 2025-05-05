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

    public function isSyncEnabled(string $configName): bool
    {
        return $this->getConfig($configName) && $this->getConfig('dataClientId') && $this->getConfig('dataClientSecret');
    }
}
