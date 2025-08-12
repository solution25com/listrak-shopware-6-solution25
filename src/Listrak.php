<?php

declare(strict_types=1);

namespace Listrak;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class Listrak extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }
        if ($this->container !== null) {
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);
            $connection->executeStatement('DROP TABLE IF EXISTS `listrak_failed_requests`');
        }
    }
}
