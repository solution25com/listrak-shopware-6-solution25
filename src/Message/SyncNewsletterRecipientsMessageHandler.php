<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\CsvService;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncNewsletterRecipientsMessageHandler
{
    public function __construct(
        private readonly CsvService $csvService,
        private readonly DataMappingService $dataMappingService,
        private readonly ListrakApiService $listrakApiService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncNewsletterRecipientsMessage $message): void
    {
        $this->logger->debug('Listrak newsletter sync started.');
        $context = $message->getContext();
        try {
            $base64File = $this->csvService->saveToCsv($context);
            if ($base64File === '') {
                $this->logger->error('Saving data for Listrak sync failed.');

                return;
            }
            $listImport = $this->dataMappingService->mapListImportData($base64File);
            $this->listrakApiService->startListImport($listImport, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Listrak newsletter sync failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
