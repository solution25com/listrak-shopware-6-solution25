<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\ContactListService;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class SyncNewsletterRecipientsMessageHandler
{
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly ContactListService $contactListService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncNewsletterRecipientsMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $restorerId = $message->getRestorerId();
        $context = Context::createDefaultContext();
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($restorerId, $context);
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addFilter(new EqualsFilter('status', 'direct'));
            $criteria->addFields(['id', 'email', 'salutation', 'firstName', 'lastName']);
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            $searchResult = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext());
            $newsletterRecipients = $searchResult->getEntities();
            $base64File = $this->contactListService->saveToCsv($newsletterRecipients, $salesChannelContext);
            if ($base64File === '') {
                return;
            }
            $listImport = $this->dataMappingService->mapListImportData($base64File, $salesChannelId);
            $this->listrakApiService->startListImport($listImport, $salesChannelContext);
            if ($searchResult->count() === $limit) {
                $nextOffset = $offset + $limit;
                $this->messageBus->dispatch(
                    new SyncNewsletterRecipientsMessage($nextOffset, $limit, $restorerId, $salesChannelId)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
