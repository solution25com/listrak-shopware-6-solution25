<?php

declare(strict_types=1);

namespace Listrak\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ListrakConfigService
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getConfig(string $configName, ?string $salesChannelId = null): mixed
    {
        return $this->systemConfigService->get('Listrak.config.' . trim($configName), $salesChannelId);
    }

    public function setConfig(string $configName, mixed $value, ?string $salesChannelId = null): void
    {
        $this->systemConfigService->set('Listrak.config.' . trim($configName), $value, $salesChannelId);
    }

    public function isDataSyncEnabled(string $configName, ?string $salesChannelId = null): bool
    {
        $enabled = trim((string) $this->getConfig($configName, $salesChannelId));
        $clientId = trim((string) $this->getConfig('emailClientId', $salesChannelId));
        $clientSecret = trim((string) $this->getConfig('emailClientSecret', $salesChannelId));

        return $enabled && $clientId !== '' && $clientSecret !== '';
    }

    public function isEmailSyncEnabled(?string $salesChannelId = null): bool
    {
        $enabled = (bool) $this->getConfig('enableNewsletterRecipientSync', $salesChannelId);
        $clientId = trim((string) $this->getConfig('emailClientId', $salesChannelId));
        $clientSecret = trim((string) $this->getConfig('emailClientSecret', $salesChannelId));
        $listId = trim((string) $this->getConfig('listId', $salesChannelId));

        return $enabled && $clientId !== '' && $clientSecret !== '' && $listId !== '';
    }
}
