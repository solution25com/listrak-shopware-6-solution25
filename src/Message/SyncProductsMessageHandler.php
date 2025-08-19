<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakFTPService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncProductsMessageHandler
{
    public function __construct(
        private readonly ListrakFTPService $listrakFtpService,
        private readonly DataMappingService $dataMappingService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncProductsMessage $message): void
    {
        $salesChannelId = $message->getSalesChannelId();
        $restorerId = $message->getRestorerId();
        $this->logger->debug(
            'Listrak product sync started for sales channel:',
            ['salesChannelId' => $salesChannelId]
        );
        $context = Context::createDefaultContext();
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($restorerId, $context);
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
