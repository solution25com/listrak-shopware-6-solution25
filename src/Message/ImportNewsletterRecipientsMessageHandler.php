<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class ImportNewsletterRecipientsMessageHandler
{
    private ListrakApiService $listrakApiService;

    private DataMappingService $dataMappingService;

    private LoggerInterface $logger;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityRepository $newsletterRecipientRepository,
        DataMappingService $dataMappingService,
        ListrakApiService $listrakApiService,
        LoggerInterface $logger,
    ) {
        $this->dataMappingService = $dataMappingService;
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    public function __invoke(ImportOrdersMessage $message): void
    {
        $this->logger->notice('Full listrak newsletter recipient sync started.');
        $context = $message->getContext();
        try {
            $criteria = new Criteria();
            $criteria->setLimit(1000);
            $criteria->addFilter(new EqualsAnyFilter('status', ['direct', 'unsubscribed']));
            $criteria->addSorting(new FieldSorting('id'));
            $iterator = new RepositoryIterator($this->newsletterRecipientRepository, $context, $criteria);
            while (($result = $iterator->fetch()) !== null) {
                $newsletterRecipients = $result->getEntities();
                $items = [];
                if (!empty($newsletterRecipients)) {
                    foreach ($newsletterRecipients as $newsletterRecipient) {
                        $item = $this->dataMappingService->mapContactData($newsletterRecipient);
                        $items[] = $item;
                    }
                }
                if (!empty($items)) {
                    $this->listrakApiService->createOrUpdateContact($newsletterRecipients, $context);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
