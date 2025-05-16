<?php

declare(strict_types=1);

namespace Listrak\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class RequestRetryTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'listrak.request_retry';
    }

    public static function getDefaultInterval(): int
    {
        return 15 * 60;
    }
}
