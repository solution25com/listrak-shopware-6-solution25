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

    /** @param iterable<mixed> $recipients */
    public function saveToCsv(iterable $recipients, SalesChannelContext $salesChannelContext): string
    {
        // @phpstan-ignore shopware.forbidLocalDiskWrite (php://temp is a temporary stream, not a persistent local export.)
        $file = fopen('php://temp', 'w+b');
        if ($file === false) {
            throw new \RuntimeException('Cannot allocate the newsletter export stream.');
        }

        try {
            $fields = ['email' => 'email'] + $this->getExportFields($salesChannelContext->getSalesChannelId());
            fputcsv($file, array_values($fields), ',', '"', '');
            $count = 0;
            foreach ($recipients as $recipient) {
                $row = $recipient instanceof \JsonSerializable ? $recipient->jsonSerialize() : (array) $recipient;
                $values = [];
                foreach ($fields as $name => $label) {
                    $value = $row[$name] ?? '';
                    if ($name === 'salutation' && $value instanceof \Shopware\Core\Framework\DataAbstractionLayer\Entity) {
                        $value = $value->get('displayName') ?? '';
                    }
                    $values[] = \is_scalar($value) ? (string) $value : '';
                }
                fputcsv($file, $values, ',', '"', '');
                ++$count;
            }
            if ($count === 0) {
                return '';
            }
            rewind($file);
            $content = stream_get_contents($file);
            if ($content === false) {
                throw new \RuntimeException('Cannot read the newsletter export stream.');
            }
            $this->logger->debug('Generated newsletter export', ['count' => $count, 'salesChannelId' => $salesChannelContext->getSalesChannelId()]);

            return base64_encode($content);
        } finally {
            fclose($file);
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
