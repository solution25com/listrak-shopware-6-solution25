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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
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
        if (!$this->listrakConfigService->isSyncEnabled('enableOrderSync')) {
            return;
        }
        $items = [];
        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() == EntityWriteResult::OPERATION_DELETE) {
                continue;
            }
            $payload = $writeResult->getPayload();
            $orderId = $payload['id'];
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('deliveries');
            $criteria->addAssociation('addresses');
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('stateMachineState');

            $order = $this->orderRepository->search(
                $criteria,
                $event->getContext()
            )->first();

            $item = $this->dataMappingService->mapOrderData($order);
            $items[] = $item;
        }

        $this->listrakApiService->importOrder($items, $event->getContext());
    }
}
