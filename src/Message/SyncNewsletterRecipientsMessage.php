<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncNewsletterRecipientsMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly ?string $salesChannelId = null
    ) {
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
