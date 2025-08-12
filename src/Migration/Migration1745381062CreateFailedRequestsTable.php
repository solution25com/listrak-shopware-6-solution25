<?php

declare(strict_types=1);

namespace Listrak\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1745381062CreateFailedRequestsTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1745381062;
    }

    public function update(Connection $connection): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `listrak_failed_requests` (
                `id` BINARY(16) NOT NULL,
                `method` VARCHAR(30) NOT NULL,
                `endpoint` VARCHAR(250) NOT NULL,
                `response` LONGTEXT,
                `options` JSON NULL,
                `retry_count` INT(11) NOT NULL DEFAULT 0,
                `last_retry_at` DATETIME(3),
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3),
                PRIMARY KEY (`id`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;';

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
