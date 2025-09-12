<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnsubscribeNewsletterRecipientMessageHandler
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UnsubscribeNewsletterRecipientMessage $message): void
    {
        $context = new Context(new SystemSource());
        $salesChannelId = $message->getSalesChannelId();
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        $customerCriteria = new Criteria();
        $customerCriteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $customerIds = $this->customerRepository->searchIds($customerCriteria, $context)->getIds();
        if (empty($customerIds)) {
            $this->logger->debug(
                'Unsubscribe newsletter recipient sync skipped',
                ['newsletterRecipientId' => $newsletterRecipientId, 'salesChannelId' => $salesChannelId]
            );
        }
        $customerId = $customerIds[0];
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($customerId, $context);
        try {
            $criteria = new Criteria([$newsletterRecipientId]);
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            $criteria->addFields(['id', 'email', 'firstName']);
            /** @var PartialEntity $newsletterRecipient */
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext())->first();
            $data = [
                'emailAddress' => $newsletterRecipient['email'],
                'subscriptionState' => 'Unsubscribed',
            ];

            $this->listrakApiService->createOrUpdateContact($data, $salesChannelContext);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
