<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class SyncOrdersMessageHandler
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly DataMappingService $dataMappingService,
        private readonly ListrakApiService $listrakApiService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncOrdersMessage $message): void
    {
        $this->logger->debug('Listrak order sync started.');
        $context = $message->getContext();
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        $orderIds = $message->getOrderIds();
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('stateMachineState');
            $criteria->addAssociation('deliveries');
            $criteria->addAssociation('billingAddress');
            $criteria->addAssociation('orderCustomer');

            $criteria->addSorting(new FieldSorting('id'));
            if ($orderIds !== null) {
                $criteria->setIds($orderIds);
            }
            $searchResult = $this->orderRepository->search($criteria, $context);
            $orders = $searchResult->getEntities();
            $this->logger->debug('Orders found for Listrak sync: ' . $orders->count());

            $items = [];
            foreach ($orders as $order) {
                $this->logger->debug('Gearing up for order sync: ');
                $item = $this->dataMappingService->mapOrderData($order, $context);
                $items[] = $item;
            }
            if (empty($items)) {
                $this->logger->debug('No orders found for Listrak sync.');

                return;
            }
            $this->listrakApiService->importOrder($items, $context);

            if ($searchResult->count() === $limit) {
                $nextOffset = $offset + $limit;
                $this->messageBus->dispatch(new SyncOrdersMessage($context, $nextOffset, $limit));
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
