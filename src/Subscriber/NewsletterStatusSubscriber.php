<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NewsletterStatusSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<NewsletterRecipientCollection> $newsletterRecipientRepository
     */
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository
    ) {
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

        $ids = $this->newsletterRecipientRepository
            ->searchIds($criteria, $event->getSalesChannelContext()->getContext())
            ->getIds();

        $isSubscribed = !empty($ids);

        $event->getPage()->addExtension('newsletterInfo', new ArrayStruct(['subscribed' => $isSubscribed]));
    }
}
