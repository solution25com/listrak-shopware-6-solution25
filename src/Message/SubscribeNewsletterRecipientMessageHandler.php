<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SubscribeNewsletterRecipientMessageHandler
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly ListrakApiService $listrakApiService,
        private readonly DataMappingService $dataMappingService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubscribeNewsletterRecipientMessage $message): void
    {
        $context = Context::createDefaultContext();
        $salesChannelId = $message->getSalesChannelId();
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        $customerCriteria = new Criteria();
        $customerCriteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $customerIds = $this->customerRepository->searchIds($customerCriteria, $context)->getIds();
        if (empty($customerIds)) {
            $this->logger->debug(
                'Listrak subscribe newsletter recipient sync skipped for sales channel:',
                ['newsletterRecipientId' => $newsletterRecipientId, 'salesChannelId' => $salesChannelId]
            );
        }
        $customerId = $customerIds[0];
        $this->logger->debug(
            'Listrak subscribe newsletter recipient sync started for sales channel:',
            ['salesChannelId' => $salesChannelId]
        );
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($customerId, $context);
        $newsletterRecipientId = $message->getNewsletterRecipientId();
        try {
            $criteria = new Criteria([$newsletterRecipientId]);
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
            $criteria->addFields(['id', 'email', 'firstName', 'lastName', 'salutation', 'status']);
            $newsletterRecipient = $this->newsletterRecipientRepository->search($criteria, $salesChannelContext->getContext())->first();
            if ($newsletterRecipient !== null) {
                $data = $this->dataMappingService->mapContactData($newsletterRecipient, $salesChannelId);
                $this->listrakApiService->createOrUpdateContact($data, $salesChannelContext);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
