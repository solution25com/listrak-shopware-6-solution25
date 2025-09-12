<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakFTPService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\SystemSource;
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
        $this->logger->debug(
            'Product sync started',
            ['salesChannelId' => $salesChannelId]
        );
        $restorerId = $message->getRestorerId();
        $context = new Context(new SystemSource());
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($restorerId, $context);
        $limit = $message->getLimit();
        $local = $message->getLocal();

        $tmp = $this->dataMappingService->mapProductData($limit, $salesChannelContext);
        if ($tmp === false) {
            $this->logger->debug('Product sync skipped', ['salesChannelId' => $message->getSalesChannelId()]);

            return;
        }
        $this->listrakFtpService->exportToFTP($local, $tmp, $salesChannelContext);
        $this->logger->debug(
            'Product sync ended',
            ['salesChannelId' => $salesChannelId]
        );
    }
}
