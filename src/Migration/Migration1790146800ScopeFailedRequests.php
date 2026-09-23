<?php

declare(strict_types=1);

namespace Listrak\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1790146800ScopeFailedRequests extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790146800;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        $columns = $schema->listTableColumns('listrak_failed_requests');
        if (!isset($columns['sales_channel_id'])) {
            $connection->executeStatement('ALTER TABLE `listrak_failed_requests` ADD `sales_channel_id` BINARY(16) NULL');
        }
        if (!isset($columns['integration_type'])) {
            $connection->executeStatement('ALTER TABLE `listrak_failed_requests` ADD `integration_type` VARCHAR(16) NULL');
        }
        if (!isset($schema->listTableIndexes('listrak_failed_requests')['idx.listrak_retry_channel'])) {
            $connection->executeStatement('ALTER TABLE `listrak_failed_requests` ADD INDEX `idx.listrak_retry_channel` (`sales_channel_id`, `retry_count`, `last_retry_at`)');
        }

        // Old rows have no reliable channel attribution. Keep their business
        // payload for manual recovery, but never replay them under another shop.
        $offset = 0;
        do {
            $rows = $connection->fetchAllAssociative(
                'SELECT `id`, `options` FROM `listrak_failed_requests` WHERE `sales_channel_id` IS NULL ORDER BY `id` LIMIT 100 OFFSET ' . $offset
            );
            foreach ($rows as $row) {
                $options = json_decode($row['options'] ?? '{}', true);
                $options = \is_array($options) ? $options : [];
                $options = array_intersect_key($options, array_flip(['body', 'json', 'headers']));
                $options['headers'] = array_filter($options['headers'] ?? [], static fn (string $name): bool =>
                    \in_array(strtolower($name), ['accept', 'content-type'], true), ARRAY_FILTER_USE_KEY);
                $connection->update('listrak_failed_requests', [
                    'options' => json_encode($options, JSON_THROW_ON_ERROR),
                    'response' => 'Legacy request: sales channel unknown; automatic replay disabled.',
                    'retry_count' => 3,
                ], ['id' => $row['id']]);
            }
            $offset += 100;
        } while (\count($rows) === 100);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
