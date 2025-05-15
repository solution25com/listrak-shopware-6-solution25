<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncOrdersMessageHandler
{
    private ListrakApiService $listrakApiService;

    private DataMappingService $dataMappingService;

    private LoggerInterface $logger;

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        DataMappingService $dataMappingService,
        ListrakApiService $listrakApiService,
        LoggerInterface $logger,
    ) {
        $this->dataMappingService = $dataMappingService;
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
    }

    public function __invoke(SyncOrdersMessage $message): void
    {
        $this->logger->notice('Full listrak order sync started.');
        $context = $message->getContext();
        try {
            $criteria = new Criteria();
            $criteria->setLimit(1000);
            $criteria->addSorting(new FieldSorting('id'));
            $iterator = new RepositoryIterator($this->orderRepository, $context, $criteria);
            while (($result = $iterator->fetch()) !== null) {
                $orders = $result->getEntities();
                $items = [];
                foreach ($orders as $order) {
                    $item = $this->dataMappingService->mapOrderData($order);
                    $items[] = $item;
                }
                if (!empty($items)) {
                    $this->listrakApiService->importOrder($items, $context);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
