<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
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
        private readonly DataMappingService $dataMappingService,
        private readonly ListrakApiService $listrakApiService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCustomersMessage $message): void
    {
        $this->logger->debug('Customer sync started.');
        $context = $message->getContext();
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        $customerIds = $message->getCustomerIds();
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addAssociation('defaultBillingAddress');
            $criteria->addAssociation('defaultShippingAddress');
            if ($customerIds !== null) {
                $criteria->setIds($customerIds);
            }
            $searchResult = $this->customerRepository->search($criteria, $context);
            $customers = $searchResult->getEntities();
            $items = [];
            foreach ($customers as $customer) {
                $item = $this->dataMappingService->mapCustomerData($customer);
                $items[] = $item;
            }
            $this->logger->debug('Customers found: ' . \count($customers));

            if (empty($items)) {
                $this->logger->debug('No customers found.');

                return;
            }

            $this->listrakApiService->importCustomer($items, $context);

            if ($searchResult->count() === $limit) {
                $nextOffset = $offset + $limit;
                $this->messageBus->dispatch(new SyncCustomersMessage($context, $nextOffset, $limit));
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
