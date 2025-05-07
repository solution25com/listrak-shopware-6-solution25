<?php declare(strict_types=1);

namespace Listrak\Core\Content\FailedRequest;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class FailedRequestDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'listrak_failed_requests';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return FailedRequestEntity::class;
    }

    public function getCollectionClass(): string
    {
        return FailedRequestCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new IntField('retry_count', 'retryCount', 0),
            new DateTimeField('last_retry_at', 'lastRetryAt'),
            new LongTextField('response', 'response'),
            new StringField('method', 'method'),
            new StringField('endpoint', 'endpoint'),
            new JsonField('options', 'options'),
        ]);
    }
}
