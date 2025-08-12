<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class CsvService
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly EntityRepository $newsletterRecipientRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function saveToCsv(Context $context): string
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'listrak_list_import_');

        $file = @fopen($tempFile, 'w');
        if ($file === false) {
            $this->logger->error("Failed to open file for writing: {$tempFile}");

            return '';
        }
        $extraFields = $this->getExportFields();
        $headers = array_merge(['email'], $extraFields);

        fputcsv($file, $headers);
        $limit = 200;
        $offset = 0;
        do {
            /** @var EntityCollection $recipients */
            $recipients = $this->fetchDirectNewsletterRecipients($context, $limit, $offset);
            foreach ($recipients as $recipient) {
                $row = $this->transformRecipientToArray($recipient);
                $csvRow = [];
                foreach ($headers as $header) {
                    $csvRow[] = $row[$header] ?? '';
                }
                fputcsv($file, $csvRow);
            }
            $offset += $limit;
        } while ($recipients->count() > 0);
        fclose($file);
        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        return base64_encode($content);
    }

    public function getExportFields(): array
    {
        $fields = [];
        $salutation = $this->listrakConfigService->getConfig('salutationSegmentationFieldId');
        $firstName = $this->listrakConfigService->getConfig('firstNameSegmentationFieldId');
        $lastName = $this->listrakConfigService->getConfig('lastNameSegmentationFieldId');
        if ($salutation) {
            $fields['salutation'] = 'Salutation';
        }
        if ($firstName) {
            $fields['firstName'] = 'First Name';
        }
        if ($lastName) {
            $fields['lastName'] = 'Last Name';
        }

        return $fields;
    }

    private function fetchDirectNewsletterRecipients($context, int $limit, int $offset)
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset($offset);
        $criteria->addFilter(new EqualsFilter('status', 'direct'));

        return $this->newsletterRecipientRepository->search($criteria, $context)->getEntities();
    }

    private function transformRecipientToArray($recipient): array
    {
        $data = [];

        $email = $recipient->getEmail();
        if (!$email) {
            return $data;
        }

        $data = [
            'email' => $email,
            'salutation' => $recipient->getSalutation(),
            'firstName' => $recipient->getFirstName(),
            'lastName' => $recipient->getLastName(),
        ];

        return $data;
    }
}
