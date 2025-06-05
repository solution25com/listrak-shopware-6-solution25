<?php declare(strict_types=1);

namespace Listrak\Message;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SyncNewsletterRecipientsMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly Context $context
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
