<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakFTPService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncProductsMessageHandler
{
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly ListrakFTPService $listrakFtpService,
        private readonly DataMappingService $dataMappingService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncProductsMessage $message): void
    {
        $this->logger->debug('Listrak product sync started for sales channel: ', [
            'salesChannelId' => $message->getSalesChannelId(),
        ]);
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
        $local = $message->getLocal();

        $tmp = $this->dataMappingService->mapProductData($offset, $limit, $salesChannelContext);
        if ($tmp === false) {
            $this->logger->debug('Listrak product sync skipped for sales channel: ', ['salesChannelId' => $message->getSalesChannelId()]);

            return;
        }
        $this->listrakFtpService->exportToFTP($local, $tmp, $salesChannelContext);
    }
}
