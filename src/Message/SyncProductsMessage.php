<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncProductsMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly bool $local = false,
        private readonly int $limit = 2000,
        private readonly ?string $restorerId = null,
        private readonly ?string $salesChannelId = null
    ) {
    }

    public function getLocal(): bool
    {
        return $this->local;
    }

    public function getLimit(): int
    {
        return $this->limit;
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
