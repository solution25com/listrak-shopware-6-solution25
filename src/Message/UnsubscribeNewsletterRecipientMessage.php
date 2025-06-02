<?php

declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class UnsubscribeNewsletterRecipientMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly string $newsletterRecipientId
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getNewsletterRecipientId(): string
    {
        return $this->newsletterRecipientId;
    }
}
