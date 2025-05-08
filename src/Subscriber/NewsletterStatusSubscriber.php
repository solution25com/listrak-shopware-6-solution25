<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NewsletterStatusSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            GenericPageLoadedEvent::class => 'onGenericPageLoaded',
        ];
    }

    public function onGenericPageLoaded(GenericPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $customer = $salesChannelContext->getCustomer();

        if (!$customer) {
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $customer->getEmail()));

        $recipient = $this->newsletterRecipientRepository
            ->search($criteria, $event->getSalesChannelContext()->getContext())
            ->first();

        $isSubscribed = $recipient !== null;

        $event->getPage()->addExtension('newsletterInfo', new ArrayStruct([
            'subscribed' => $isSubscribed,
        ]));
    }
}
