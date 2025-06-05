<?php

declare(strict_types=1);

namespace Listrak\Message;

use Listrak\Service\CsvService;
use Listrak\Service\DataMappingService;
use Listrak\Service\ListrakApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncNewsletterRecipientsMessageHandler
{
    public function __construct(
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly CsvService $csvService,
        private readonly DataMappingService $dataMappingService,
        private readonly ListrakApiService $listrakApiService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncNewsletterRecipientsMessage $message): void
    {
        $this->logger->debug('Sync newsletter recipients message started.');
        $context = $message->getContext();

        try {
            /** @var EntityCollection $recipients */
            $recipients = $this->fetchDirectNewsletterRecipients($context);

            if ($recipients->count() === 0) {
                $this->logger->debug('No newsletter recipients found.');

                return;
            }
            $this->logger->debug('Newsletter recipients found: ' . \count($recipients));

            $data = $this->transformRecipientsToArray($recipients);

            if (empty($data)) {
                $this->logger->debug('No newsletter recipient data found.');

                return;
            }

            $this->csvService->saveToCsv($data);

            $base64File = $this->csvService->encodeFileToBase64();
            $listImport = $this->dataMappingService->mapListImportData($base64File);
            $this->listrakApiService->startListImport($listImport, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Newsletter sync failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function fetchDirectNewsletterRecipients(Context $context): EntityCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('status', 'direct'));

        return $this->newsletterRecipientRepository->search($criteria, $context)->getEntities();
    }

    private function transformRecipientsToArray(EntityCollection $recipients): array
    {
        $data = [];

        foreach ($recipients as $recipient) {
            if ($recipient instanceof NewsletterRecipientEntity) {
                $email = $recipient->getEmail();
                if (!$email) {
                    continue; // skip invalid
                }

                $data[] = [
                    'email' => $email,
                    'salutation' => $recipient->getSalutation(),
                    'firstName' => $recipient->getFirstName(),
                    'lastName' => $recipient->getLastName(),
                ];
            }
        }

        return $data;
    }
}
