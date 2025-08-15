<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncCustomersMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly int $offset = 0,
        private readonly int $limit = 500,
        private readonly ?array $customerIds = null,
        private readonly ?string $restorerId = null,
        private readonly ?string $salesChannelId = null
    ) {
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getCustomerIds(): ?array
    {
        return $this->customerIds;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    public function getRestorerId(): ?string
    {
        return $this->restorerId;
    }
}
