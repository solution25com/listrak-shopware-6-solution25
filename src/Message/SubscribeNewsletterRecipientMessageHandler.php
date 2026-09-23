<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SubscribeNewsletterRecipientMessageHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientCollection> $newsletterRecipientRepository
     */
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubscribeNewsletterRecipientMessage $message): void
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
            $criteria->addFields(['id', 'email', 'firstName', 'lastName', 'salutation.displayName', 'status']);
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext())->getEntities()->first();
            if ($newsletterRecipient !== null) {
                $data = $this->dataMappingService->mapContactData($newsletterRecipient, $salesChannelId);
                $this->listrakApiService->createOrUpdateContact($data, $salesChannelContext);
            }
        } catch (\Exception $e) {
            $this->logger->error('Listrak synchronization failed', ['salesChannelId' => $salesChannelId, 'exceptionClass' => $e::class]);
            throw $e;
        }
    }
}
