<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UnsubscribeNewsletterRecipientMessageHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientCollection> $newsletterRecipientRepository
     */
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UnsubscribeNewsletterRecipientMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        if ($salesChannelId === null) {
            throw new \InvalidArgumentException('Listrak newsletter message is missing its sales channel.');
        }
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId);
        try {
            $criteria = new Criteria([$newsletterRecipientId]);
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            $criteria->addFields(['id', 'email', 'status']);
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext())->getEntities()->first();
            if ($newsletterRecipient === null || $newsletterRecipient->get('status') !== \Shopware\Core\Content\Newsletter\SalesChannel\NewsletterSubscribeRoute::STATUS_OPT_OUT) {
                return;
            }
            $data = [
                'emailAddress' => $newsletterRecipient->get('email'),
                'subscriptionState' => 'Unsubscribed',
            ];

            $this->listrakApiService->createOrUpdateContact($data, $salesChannelContext);
        } catch (\Exception $e) {
            $this->logger->error('Listrak synchronization failed', ['salesChannelId' => $salesChannelId, 'exceptionClass' => $e::class]);
            throw $e;
        }
    }
}
