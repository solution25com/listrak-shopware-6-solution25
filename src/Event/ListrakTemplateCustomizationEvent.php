<?php

declare(strict_types=1);

namespace Listrak\Event;

use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;

class ListrakTemplateCustomizationEvent implements ShopwareEvent
{
    public const NAME = 'listrak.template_customization_event';

    private StorableFlow $flow;

    public function __construct(StorableFlow $flow)
    {
        $this->flow = $flow;
    }

    public function getFlow(): StorableFlow
    {
        return $this->flow;
    }

    public function getContext(): Context
    {
        return $this->flow->getContext();
    }
}
