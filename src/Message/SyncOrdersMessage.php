<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncOrdersMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly int $offset = 0,
        private readonly int $limit = 300,
        private readonly ?array $orderIds = null,
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

    public function getOrderIds(): ?array
    {
        return $this->orderIds;
    }

    public function getRestorerId(): ?string
    {
        return $this->restorerId;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
