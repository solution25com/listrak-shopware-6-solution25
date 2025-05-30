<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncCustomersMessageHandler
{
    private ListrakApiService $listrakApiService;

    private DataMappingService $dataMappingService;

    private LoggerInterface $logger;

    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        DataMappingService $dataMappingService,
        ListrakApiService $listrakApiService,
        LoggerInterface $logger,
    ) {
        $this->dataMappingService = $dataMappingService;
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    public function __invoke(SyncCustomersMessage $message): void
    {
        $this->logger->notice('Full Listrak customer sync started.');
        $context = $message->getContext();
        try {
            $criteria = new Criteria();
            $criteria->setLimit(1000);
            $criteria->addSorting(new FieldSorting('id'));
            $iterator = new RepositoryIterator($this->customerRepository, $context, $criteria);
            while (($result = $iterator->fetch()) !== null) {
                $customers = $result->getEntities();
                $items = [];
                foreach ($customers as $customer) {
                    $item = $this->dataMappingService->mapCustomerData($customer);
                    $items[] = $item;
                }
                if (!empty($items)) {
                    $this->listrakApiService->importCustomer($items, $context);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
