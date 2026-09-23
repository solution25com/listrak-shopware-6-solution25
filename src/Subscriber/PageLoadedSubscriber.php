<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\GenericPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PageLoadedSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<NewsletterRecipientCollection> $newsletterRecipientRepository
     * @param EntityRepository<\Shopware\Core\System\Currency\CurrencyCollection> $currencyRepository
     */
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly EntityRepository $currencyRepository,
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
        $usdCriteria = new Criteria();
        $usdCriteria->addFilter(new EqualsFilter('isoCode', 'USD'));
        $usdCriteria->addFields(['id', 'isoCode', 'factor']);
        $usdCurrency = $this->currencyRepository->search(
            $usdCriteria,
            $event->getSalesChannelContext()->getContext()
        )->getEntities()->first();

        if (!$customer) {
            $event->getPage()->addExtension('listrakInfo', new ArrayStruct(['subscribed' => null, 'status' => null, 'usdCurrency' => $usdCurrency]));

            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $customer->getEmail()));
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelContext->getSalesChannelId()));
        $criteria->addFilter(new OrFilter([new EqualsFilter('status', 'direct'), new EqualsFilter('status', 'optIn'), new EqualsFilter('status', 'notSet')]));
        $criteria->addFields(['id', 'status']);
        $criteria->setLimit(1);
        $recipient = $this->newsletterRecipientRepository
            ->search($criteria, $event->getSalesChannelContext()->getContext())
            ->getEntities()->first();

        $isSubscribed = $recipient !== null;
        $status = $recipient?->get('status');

        $event->getPage()->addExtension('listrakInfo', new ArrayStruct(['subscribed' => $isSubscribed, 'status' => $status, 'usdCurrency' => $usdCurrency]));
    }
}
