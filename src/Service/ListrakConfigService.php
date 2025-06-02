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
        $enabled = trim((string) $this->getConfig($configName));
        $clientId = trim((string) $this->getConfig('emailClientId'));
        $clientSecret = trim((string) $this->getConfig('emailClientSecret'));

        return $enabled && $clientId !== '' && $clientSecret !== '';
    }

    public function isEmailSyncEnabled(): bool
    {
        $enabled = (bool) $this->getConfig('enableNewsletterRecipientSync');
        $clientId = trim((string) $this->getConfig('emailClientId'));
        $clientSecret = trim((string) $this->getConfig('emailClientSecret'));
        $listId = trim((string) $this->getConfig('listId'));

        return $enabled && $clientId !== '' && $clientSecret !== '' && $listId !== '';
    }
}
