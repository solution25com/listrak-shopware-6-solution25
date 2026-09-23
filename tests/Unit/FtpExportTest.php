<?php declare(strict_types=1);

namespace Listrak\Tests\Unit;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use Listrak\Service\ListrakConfigService;
use Listrak\Service\ListrakFTPService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class FtpExportTest extends TestCase
{
    public function testFailedLocalExportIsNotAcknowledgedAsASuccessfulQueueJob(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('fileExists')->willReturn(false);
        $filesystem->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('fixture'));
        $service = new ListrakFTPService($this->createMock(ListrakConfigService::class), $filesystem, new NullLogger());
        $file = tempnam(sys_get_temp_dir(), 'listrak-test-');
        file_put_contents($file, 'fixture');
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('fixture');
        $this->expectException(\RuntimeException::class);
        try {
            $service->exportToFTP(true, $file, $context);
        } finally {
            self::assertFileDoesNotExist($file);
        }
    }
}
