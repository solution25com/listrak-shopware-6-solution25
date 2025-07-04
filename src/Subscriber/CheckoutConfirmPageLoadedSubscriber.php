<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Pagelet\Newsletter\Account\NewsletterAccountPageletLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmPageLoadedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NewsletterAccountPageletLoader $newsletterAccountPageletLoader
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmPageLoaded',
        ];
    }

    public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $customer = $context->getCustomer();
        $request = $event->getRequest();
        $pagelet = $this->newsletterAccountPageletLoader->load($request, $context, $customer);
        $event->getPage()->addExtension('newsletterAccountPagelet', $pagelet);
    }
}
