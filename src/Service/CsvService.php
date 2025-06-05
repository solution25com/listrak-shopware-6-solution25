<?php declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;

class CsvService
{
    public function __construct(
        private ListrakConfigService $listrakConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function saveToCsv(array $data): void
    {
        $path = '/var/www/html/public/listrak_list_import.csv';

        if (empty($data)) {
            $this->logger->warning('No data provided for CSV export.');

            return;
        }

        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $file = @fopen($path, 'w');
        if ($file === false) {
            $this->logger->error("Failed to open file for writing: {$path}");

            return;
        }

        $extraFields = $this->getExportFields();
        $headers = array_merge(['email'], $extraFields);

        fputcsv($file, $headers);

        foreach ($data as $row) {
            $csvRow = [];
            foreach ($headers as $header) {
                $csvRow[] = $row[$header] ?? '';
            }
            fputcsv($file, $csvRow);
        }

        fclose($file);

        $this->logger->debug('CSV export successful', ['path' => $path]);
    }

    public function encodeFileToBase64(): string
    {
        $path = '/var/www/html/public/listrak_list_import.csv';
        $content = file_get_contents($path);

        return base64_encode($content);
    }

    private function getExportFields(): array
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
}
