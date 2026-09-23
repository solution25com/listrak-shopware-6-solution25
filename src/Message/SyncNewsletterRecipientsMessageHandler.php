<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\ContactListService;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Content\Newsletter\SalesChannel\NewsletterSubscribeRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class SyncNewsletterRecipientsMessageHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientCollection> $newsletterRecipientRepository
     */
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly ContactListService $contactListService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncNewsletterRecipientsMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $restorerId = $message->getRestorerId();
        if ($salesChannelId === null) {
            throw new \InvalidArgumentException('Listrak sync message is missing its sales channel.');
        }
        $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId);
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addFilter(new EqualsAnyFilter('status', [NewsletterSubscribeRoute::STATUS_DIRECT, NewsletterSubscribeRoute::STATUS_OPT_IN]));
            $criteria->addFields(['id', 'email', 'salutation.displayName', 'firstName', 'lastName']);
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            $searchResult = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext());
            $newsletterRecipients = $searchResult->getEntities();
            $base64File = $this->contactListService->saveToCsv($newsletterRecipients, $salesChannelContext);
            if ($base64File === '') {
                return;
            }
            $listImport = $this->dataMappingService->mapListImportData($base64File, $salesChannelId);
            $this->listrakApiService->startListImport($listImport, $salesChannelContext);
            if ($searchResult->getEntities()->count() === $limit) {
                $nextOffset = $offset + $limit;
                $this->messageBus->dispatch(
                    new SyncNewsletterRecipientsMessage($nextOffset, $limit, $restorerId, $salesChannelId)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Listrak synchronization failed', ['salesChannelId' => $salesChannelId, 'exceptionClass' => $e::class]);
            throw $e;
        }
    }
}
