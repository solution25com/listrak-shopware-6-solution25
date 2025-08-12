<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerCollection;
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
final class SyncCustomersMessageHandler
{
    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncCustomersMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $this->logger->debug(
            'Listrak customer sync started for sales channel:',
            ['salesChannelId' => $salesChannelId]
        );
        $context = Context::createDefaultContext();
        $criteria = new Criteria([$message->getSalesChannelId()]);
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannel->getId(),
        );
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        $customerIds = $message->getCustomerIds();
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addAssociation('defaultBillingAddress');
            $criteria->addAssociation('defaultBillingAddress.country');
            $criteria->addAssociation('defaultShippingAddress');
            $criteria->addAssociation('group');
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            if ($customerIds !== null) {
                $criteria->setIds($customerIds);
            }
            $searchResult = $this->customerRepository->search($criteria, $salesChannelContext->getContext());
            $customers = $searchResult->getEntities();
            $items = [];
            foreach ($customers as $customer) {
                $item = $this->dataMappingService->mapCustomerData($customer);
                $items[] = $item;
            }
            $this->logger->debug('Customers found for Listrak sync: ' . \count($customers));

            if (empty($items)) {
                $this->logger->debug('No customers found for Listrak sync.');

                return;
            }

            $this->listrakApiService->importCustomer($items, $salesChannelContext->getContext(), $salesChannelId);

            if ($searchResult->count() === $limit) {
                $nextOffset = $offset + $limit;
                $this->messageBus->dispatch(new SyncCustomersMessage($nextOffset, $limit, null, $salesChannelId));
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
