<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SubscribeNewsletterRecipientMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly string $newsletterRecipientId,
        private readonly ?string $salesChannelId = null,
    ) {
    }

    public function getNewsletterRecipientId(): string
    {
        return $this->newsletterRecipientId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }
}
