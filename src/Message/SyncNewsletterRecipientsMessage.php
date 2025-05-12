<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncNewsletterRecipientsMessage implements AsyncMessageInterface
{
    /**
     * @internal
     */
    public function __construct(
        private readonly Context $context,
        private readonly int $offset = 0
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
}
