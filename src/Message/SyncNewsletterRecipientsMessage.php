<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncNewsletterRecipientsMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly int $offset = 0,
        private readonly int $limit = 3000,
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

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }

    public function getRestorerId(): ?string
    {
        return $this->restorerId;
    }
}
