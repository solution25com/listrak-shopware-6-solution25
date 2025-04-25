<?php declare(strict_types=1);

namespace Listrak\Core\Content\FailedRequest;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(FailedRequestEntity $entity)
 * @method void set(string $key, FailedRequestEntity $entity)
 * @method FailedRequestEntity[] getIterator()
 * @method FailedRequestEntity[] getElements()
 * @method FailedRequestEntity|null get(string $key)
 * @method FailedRequestEntity|null first()
 * @method FailedRequestEntity|null last()
 * @extends EntityCollection<FailedRequestEntity>
 */
class FailedRequestCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return FailedRequestEntity::class;
    }
}
