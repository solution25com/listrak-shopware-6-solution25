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
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
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
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncCustomersMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $restorerId = $message->getRestorerId();
        $this->logger->debug(
            'Listrak customer sync started for sales channel:',
            ['salesChannelId' => $salesChannelId]
        );
        $context = Context::createDefaultContext();
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($restorerId, $context);
        $offset = $message->getOffset();
        $limit = $message->getLimit();
        $customerIds = $message->getCustomerIds();
        try {
            $criteria = new Criteria();
            $criteria->setOffset($offset);
            $criteria->setLimit($limit);
            $criteria->addSorting(new FieldSorting('id'));
            $criteria->addAssociation('activeBillingAddress');
            $criteria->addAssociation('defaultBillingAddress');
            $criteria->addAssociation('group');
            $criteria->addFields(
                [
                    'customerNumber',
                    'email',
                    'firstName',
                    'lastName',
                    'birthday',
                    'guest',
                    'group.name',
                    'activeBillingAddress.country.name',
                    'activeBillingAddress.street',
                    'activeBillingAddress.firstName',
                    'activeBillingAddress.lastName',
                    'activeBillingAddress.zipcode',
                    'activeBillingAddress.additionalAddressLine1',
                    'activeBillingAddress.additionalAddressLine2',
                    'activeBillingAddress.city',
                    'activeBillingAddress.countryState.name',
                    'defaultBillingAddress.country.name',
                    'defaultBillingAddress.street',
                    'defaultBillingAddress.firstName',
                    'defaultBillingAddress.lastName',
                    'defaultBillingAddress.zipcode',
                    'defaultBillingAddress.additionalAddressLine1',
                    'defaultBillingAddress.additionalAddressLine2',
                    'defaultBillingAddress.city',
                    'defaultBillingAddress.countryState.name',
                    'activeBillingAddress.phoneNumber',
                    'defaultBillingAddress.phoneNumber',
                ]
            );
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
                $this->messageBus->dispatch(
                    new SyncCustomersMessage($nextOffset, $limit, null, $restorerId, $salesChannelId)
                );
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
