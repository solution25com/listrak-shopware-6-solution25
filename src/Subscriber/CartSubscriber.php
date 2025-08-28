<?php
declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\CartRecreateLinkBuilder;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CartSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CartRecreateLinkBuilder $builder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OffcanvasCartPageLoadedEvent::class => 'onCartLoaded',
            CheckoutCartPageLoadedEvent::class => 'onCartLoaded',
            CheckoutConfirmPageLoadedEvent::class => 'onCartLoaded',
        ];
    }

    public function onCartLoaded(PageLoadedEvent $event): void
    {
        $cart = $event->getPage()->getCart();
        $scId = $event->getSalesChannelContext()->getSalesChannelId();

        $link = $this->builder->build($cart, $scId);

        $event->getPage()->addExtension('listrakCartRecreate', new ArrayStruct([
            'link' => $link,
        ]));
    }
}
