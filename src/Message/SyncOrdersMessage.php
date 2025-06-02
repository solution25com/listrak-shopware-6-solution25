<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncOrdersMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly int $offset = 0,
        private readonly int $limit = 500,
        private readonly ?array $orderIds = null
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
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
}
