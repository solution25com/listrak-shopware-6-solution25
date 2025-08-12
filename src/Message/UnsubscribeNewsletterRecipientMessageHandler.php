<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;

#[AsMessageHandler]
final class UnsubscribeNewsletterRecipientMessageHandler
{
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UnsubscribeNewsletterRecipientMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $this->logger->debug('Listrak unsubscribe newsletter recipient sync started for sales channel: ', [
            'salesChannelId' => $message->getSalesChannelId(),
        ]);
        $context = Context::createDefaultContext();
        $criteria = new Criteria([$message->getSalesChannelId()]);
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannel->getId(),
        );
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        try {
            $criteria = new Criteria([$newsletterRecipientId]);
            /** @var NewsletterRecipientEntity|null $newsletterRecipient */
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext())->first();
            $data = [
                'emailAddress' => $newsletterRecipient->getEmail(),
                'subscriptionState' => 'Unsubscribed',
            ];

            $this->listrakApiService->createOrUpdateContact($data, $salesChannelContext);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
