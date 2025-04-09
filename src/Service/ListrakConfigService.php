<?php declare(strict_types=1);

namespace Solu1Listrak\Service;
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
        return $this->systemConfigService->get('Solu1Listrak.config.' . trim($configName));
    }

}