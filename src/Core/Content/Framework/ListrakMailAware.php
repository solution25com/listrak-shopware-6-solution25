<?php

declare(strict_types=1);

namespace Listrak\Core\Content\Framework;

use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;

interface ListrakMailAware extends FlowEventAware, MailAware
{
}
