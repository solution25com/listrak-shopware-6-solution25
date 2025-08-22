<?php

declare(strict_types=1);

namespace Listrak\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ContactListService
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function saveToCsv($recipients, SalesChannelContext $salesChannelContext): string
    {
        $this->logger->debug('Generating contact list', [
            'recipientCount' => is_countable($recipients) ? \count($recipients) : null,
            'recipientsType' => \is_object($recipients) ? $recipients::class : \gettype($recipients),
            'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
        ]);

        if (!is_iterable($recipients)) {
            $this->logger->error('Contact list generation: $recipients is not iterable', ['salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

            return '';
        }

        $tempDir = sys_get_temp_dir();
        $formattedDate = (new \DateTimeImmutable())->format('YmdHis');
        $tempFile = tempnam($tempDir, 'Listrak_Contact_Import_' . $formattedDate);

        if ($tempFile === false) {
            $this->logger->error('Contact list generation: tempnam failed', ['tempDir' => $tempDir, 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

            return '';
        }

        $file = fopen($tempFile, 'wb');
        if ($file === false) {
            $this->logger->error('Contact list generation: fopen failed', ['tempFile' => $tempFile, 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

            return '';
        }

        try {
            $extraFields = $this->getExportFields($salesChannelContext->getSalesChannel()->getId());

            $headers = array_merge(['email'], $extraFields);
            $this->logger->debug('Contact list generation: headers prepared', ['headers' => $headers]);

            $delimiter = ',';
            $enclosure = '"';

            if (fputcsv($file, $headers, $delimiter, $enclosure) === false) {
                $this->logger->error('Contact list generation: failed to write header row', ['salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

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
                    $this->logger->warning('Contact list generation: recipient not array/object', ['type' => \gettype($recipient), 'index' => $rowIndex, 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);
                    $row = [];
                }

                $csvRow = [];
                foreach ($headers as $h) {
                    if (!\array_key_exists($h, $row)) {
                        if ($rowIndex < 5) {
                            $this->logger->debug('Contact list generation: missing key in row', ['key' => $h, 'index' => $rowIndex, 'availableKeys' => array_keys($row), 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);
                        }
                        $csvRow[] = '';
                    } else {
                        $csvRow[] = \is_scalar($row[$h]) ? (string) $row[$h] : json_encode($row[$h], \JSON_UNESCAPED_UNICODE);
                    }
                }

                $bytes = fputcsv($file, $csvRow, $delimiter, $enclosure);
                if ($bytes === false) {
                    $this->logger->error('Contact list generation: fputcsv failed', ['index' => $rowIndex, 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

                    return '';
                }

                ++$rowIndex;
            }

            $this->logger->debug('Contact list generation: finished writing rows', ['rows' => $rowIndex, 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

            fflush($file);
            fclose($file);
            $file = null;

            $content = file_get_contents($tempFile);
            if ($content === false) {
                $this->logger->error('Contact list generation: file_get_contents failed', ['tempFile' => $tempFile, 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

                return '';
            }

            $encoded = base64_encode($content);
            if (!$encoded) {
                $this->logger->error('Contact list generation: base64_encode failed', ['bytes' => \strlen($content), 'salesChannelId' => $salesChannelContext->getSalesChannel()->getId()]);

                return '';
            }

            $this->logger->debug('Contact list generation was successful', [
                'bytesRaw' => \strlen($content),
                'bytesBase64' => \strlen($encoded),
                'tempFile' => $tempFile,
            ]);

            @unlink($tempFile);

            return $encoded;
        } catch (\Throwable $e) {
            $this->logger->error('Contact list generation failed', ['exception' => $e]);
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
