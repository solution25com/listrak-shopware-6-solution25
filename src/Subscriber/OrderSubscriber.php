<?php

declare(strict_types=1);

namespace Listrak\Subscriber;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    private ListrakConfigService $listrakConfigService;

    private ListrakApiService $listrakApiService;

    private DataMappingService $dataMappingService;

    private LoggerInterface $logger;

    public function __construct(
        ListrakConfigService $listrakConfigService,
        ListrakApiService $listrakApiService,
        DataMappingService $dataMappingService,
        private readonly EntityRepository $orderRepository,
        LoggerInterface $logger
    ) {
        $this->listrakConfigService = $listrakConfigService;
        $this->listrakApiService = $listrakApiService;
        $this->dataMappingService = $dataMappingService;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        if (!$this->listrakConfigService->isDataSyncEnabled('enableOrderSync')) {
            return;
        }
        $this->logger->debug('Listrak order written event triggered');
        $ids = [];
        $items = [];

        foreach ($event->getWriteResults() as $writeResult) {
            $id = $writeResult->getPrimaryKey();
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE && !$id) {
                continue;
            }
            $ids[] = $id;
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('stateMachineState');

        $orders = $this->orderRepository->search(
            $criteria,
            $event->getContext()
        )->getEntities();

        foreach ($orders as $order) {
            $item = $this->dataMappingService->mapOrderData($order);
            $items[] = $item;
        }

        $this->listrakApiService->importOrder($items, $event->getContext());
    }
}
