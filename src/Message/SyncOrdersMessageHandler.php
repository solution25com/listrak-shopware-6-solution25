<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
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
        $restorerId = $message->getRestorerId();
        if ($salesChannelId === null) {
            throw new \InvalidArgumentException('Listrak sync message is missing its sales channel.');
        }
        $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId);
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        $orderIds = $message->getOrderIds();
        $paginate = ($orderIds === null);
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('stateMachineState');
            $criteria->addAssociation('billingAddress');
            $criteria->addAssociation('orderCustomer');
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            $criteria->addFields(['id', 'orderNumber', 'shippingTotal', 'totalPrice', 'orderDateTime', 'currencyFactor', 'currency.isoCode', 'currency.factor',
                'lineItems.id', 'lineItems.type', 'lineItems.payload', 'lineItems.unitPrice', 'lineItems.totalPrice', 'lineItems.quantity',
                'billingAddress.id',
                'billingAddress.versionId',
                'billingAddress.firstName',
                'billingAddress.lastName',
                'billingAddress.street',
                'billingAddress.zipcode',
                'billingAddress.city',
                'billingAddress.additionalAddressLine1',
                'billingAddress.additionalAddressLine2',
                'billingAddress.country.id',
                'billingAddress.country.name',
                'billingAddress.countryState.id',
                'billingAddress.countryState.name',
                'stateMachineState.id',
                'stateMachineState.technicalName',
                'orderCustomer.id',
                'orderCustomer.versionId',
                'orderCustomer.orderId',
                'orderCustomer.orderVersionId',
                'orderCustomer.email',
                'orderCustomer.customerNumber',
                'price']);
            if ($orderIds !== null) {
                $criteria->setIds($orderIds);
            }
            $searchResult = $this->orderRepository->search($criteria, $salesChannelContext->getContext());
            $orders = $searchResult->getEntities();
            $items = [];
            foreach ($orders as $order) {
                $item = $this->dataMappingService->mapOrderData($order, $salesChannelContext);
                $items[] = $item;
            }
            $this->listrakApiService->exportOrder($items, $salesChannelContext);
            if ($paginate) {
                if ($searchResult->getEntities()->count() === $limit) {
                    $nextOffset = $offset + $limit;
                    $this->messageBus->dispatch(new SyncOrdersMessage($nextOffset, $limit, null, $restorerId, $salesChannelId));
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Listrak synchronization failed', ['salesChannelId' => $salesChannelId, 'exceptionClass' => $e::class]);
            throw $e;
        } catch (ExceptionInterface $e) {
            $this->logger->error('Listrak synchronization failed', ['salesChannelId' => $salesChannelId, 'exceptionClass' => $e::class]);
            throw $e;
        }
    }
}
