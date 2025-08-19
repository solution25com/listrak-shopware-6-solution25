<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CsvService
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function saveToCsv($recipients, SalesChannelContext $salesChannelContext): string
    {
        $this->logger->info('Listrak newsletter recipient file: start', [
            'recipientCount' => is_countable($recipients) ? \count($recipients) : null,
            'recipientsType' => \is_object($recipients) ? $recipients::class : \gettype($recipients),
        ]);

        if (!is_iterable($recipients)) {
            $this->logger->error('Listrak newsletter recipient file: $recipients is not iterable');

            return '';
        }

        $tempDir = sys_get_temp_dir();
        $formattedDate = (new \DateTimeImmutable())->format('YmdHis');
        $tempFile = tempnam($tempDir, 'Listrak_Contact_Import_' . $formattedDate);

        if ($tempFile === false) {
            $this->logger->error('Listrak newsletter recipient file: tempnam failed', ['tempDir' => $tempDir]);

            return '';
        }

        $file = fopen($tempFile, 'wb');
        if ($file === false) {
            $this->logger->error('Listrak newsletter recipient file: fopen failed', ['tempFile' => $tempFile]);

            return '';
        }

        try {
            $extraFields = $this->getExportFields($salesChannelContext->getSalesChannel()->getId());

            $headers = array_merge(['email'], $extraFields);
            $this->logger->info('Listrak newsletter recipient file: headers prepared', ['headers' => $headers]);

            $delimiter = ',';
            $enclosure = '"';

            if (fputcsv($file, $headers, $delimiter, $enclosure) === false) {
                $this->logger->error('Listrak newsletter recipient file: failed to write header row');

                return '';
            }

            $rowIndex = 0;
            foreach ($recipients as $recipient) {
                if ($recipient instanceof \JsonSerializable) {
                    $row = (array) $recipient->jsonSerialize();
                } elseif (\is_object($recipient)) {
                    $row = get_object_vars($recipient);
                } elseif (\is_array($recipient)) {
                    $row = $recipient;
                } else {
                    $this->logger->warning('Listrak newsletter recipient file: recipient not array/object', ['type' => \gettype($recipient), 'index' => $rowIndex]);
                    $row = [];
                }

                $csvRow = [];
                foreach ($headers as $h) {
                    if (!\array_key_exists($h, $row)) {
                        if ($rowIndex < 5) {
                            $this->logger->debug('Listrak newsletter recipient file: missing key in row', ['key' => $h, 'index' => $rowIndex, 'availableKeys' => array_keys($row)]);
                        }
                        $csvRow[] = '';
                    } else {
                        $csvRow[] = \is_scalar($row[$h]) ? (string) $row[$h] : json_encode($row[$h], \JSON_UNESCAPED_UNICODE);
                    }
                }

                $bytes = fputcsv($file, $csvRow, $delimiter, $enclosure);
                if ($bytes === false) {
                    $this->logger->error('Listrak newsletter recipient file: fputcsv failed', ['index' => $rowIndex]);

                    return '';
                }

                ++$rowIndex;
            }

            $this->logger->info('Listrak newsletter recipient file: finished writing rows', ['rows' => $rowIndex]);

            fflush($file);
            fclose($file);
            $file = null;

            $content = file_get_contents($tempFile);
            if ($content === false) {
                $this->logger->error('Listrak newsletter recipient file: file_get_contents failed', ['tempFile' => $tempFile]);

                return '';
            }

            $encoded = base64_encode($content);
            if (!$encoded) {
                $this->logger->error('Listrak newsletter recipient file: base64_encode failed', ['bytes' => \strlen($content)]);

                return '';
            }

            $this->logger->info('Listrak newsletter recipient file: success', [
                'bytesRaw' => \strlen($content),
                'bytesBase64' => \strlen($encoded),
                'tempFile' => $tempFile,
            ]);

            @unlink($tempFile);

            return $encoded;
        } catch (\Throwable $e) {
            $this->logger->error('Listrak newsletter recipient file: exception', ['exception' => $e]);
            try {
                if (\is_resource($file)) {
                    fclose($file);
                }
            } catch (\Throwable $e2) {
            }
            @unlink($tempFile);

            return '';
        }
    }

    public function getExportFields(?string $salesChannelId): array
    {
        $fields = [];
        $salutation = $this->listrakConfigService->getConfig('salutationSegmentationFieldId', $salesChannelId);
        $firstName = $this->listrakConfigService->getConfig('firstNameSegmentationFieldId', $salesChannelId);
        $lastName = $this->listrakConfigService->getConfig('lastNameSegmentationFieldId', $salesChannelId);
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
