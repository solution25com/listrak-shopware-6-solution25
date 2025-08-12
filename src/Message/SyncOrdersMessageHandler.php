<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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
        private readonly EntityRepository $salesChannelRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncOrdersMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $this->logger->debug(
            'Listrak order sync started for saleschannel:',
            ['salesChannelId' => $message->getSalesChannelId()]
        );
        $context = Context::createDefaultContext();
        $criteria = new Criteria([$salesChannelId]);
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannel->getId(),
        );
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
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));

            $criteria->addSorting(new FieldSorting('id'));
            if ($orderIds !== null) {
                $criteria->setIds($orderIds);
            }
            $searchResult = $this->orderRepository->search($criteria, $salesChannelContext->getContext());
            $orders = $searchResult->getEntities();
            $this->logger->debug('Orders found for Listrak sync: ' . $orders->count());

            $items = [];
            foreach ($orders as $order) {
                $item = $this->dataMappingService->mapOrderData($order, $salesChannelContext);
                $items[] = $item;
            }
            if (empty($items)) {
                $this->logger->debug('No orders found for Listrak sync.');

                return;
            }
            $this->listrakApiService->importOrder($items, $salesChannelContext);

            if ($searchResult->count() === $limit) {
                $nextOffset = $offset + $limit;
                $this->messageBus->dispatch(new SyncOrdersMessage($nextOffset, $limit, null, $salesChannelId));
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
