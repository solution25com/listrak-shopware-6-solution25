<?php

declare(strict_types=1);

namespace Listrak\Service;

use FtpClient\FtpClient;
use FtpClient\FtpException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ListrakFTPService
{
    public const PORT = 21;

    private const HOST = 'ftp.listrak.com';

    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly FilesystemOperator $fileSystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function exportToFTP(bool $local, string $tmp, SalesChannelContext $salesChannelContext): void
    {
        $user = $this->listrakConfigService->getConfig('ftpUsername', $salesChannelContext->getSalesChannelId());
        $pass = $this->listrakConfigService->getConfig('ftpPassword', $salesChannelContext->getSalesChannelId());
        $formattedDate = (new \DateTimeImmutable())->format('YmdHis');
        $remote = 'Listrak_Product_Feed_' . $salesChannelContext->getSalesChannelId() . '_' . $formattedDate . '.txt';

        if ($local) {
            $this->generateLocalFile($remote, $tmp);

            return;
        }

        $ftp = new FtpClient();

        $remoteTmp = $remote . '.part';
        try {
            $this->logger->debug('Connecting to FTP', ['host' => self::HOST, 'port' => self::PORT]);
            $ftp->connect(self::HOST, false, self::PORT);
            $ftp->login($user, $pass);
            $ftp->pasv(true);

            $remoteDir = rtrim(\dirname($remote), '/');
            if ($remoteDir !== '' && $remoteDir !== '.') {
                $this->ftpMkdirRecursive($ftp, $remoteDir);
            }

            $this->logger->debug('Uploading to FTP (atomic)', ['tmp' => $remoteTmp, 'final' => $remote]);
            $ftp->put($remoteTmp, $tmp, FTP_BINARY);
            $ftp->rename($remoteTmp, $remote);

            $this->logger->debug('File successfully exported to FTP', [
                'file' => $remote,
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Export to FTP failed', [
                'exception' => $e,
                'file' => $remote,
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
            ]);
            try {
                $ftp->delete($remoteTmp);
            } catch (\Throwable) {
            }
        } finally {
            try {
                $ftp->close();
            } catch (\Throwable) {
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function generateLocalFile(string $fileKey, string $tmpPath): bool
    {
        $in = @fopen($tmpPath, 'rb');
        if ($in === false) {
            $this->logger->error('Cannot open temp file', ['tmp' => $tmpPath]);

            return false;
        }

        $tmpKey = $fileKey . '.part';

        try {
            if ($this->fileSystem->fileExists($tmpKey)) {
                $this->fileSystem->delete($tmpKey);
            }
            $this->fileSystem->writeStream($tmpKey, $in);
            fclose($in);

            if ($this->fileSystem->fileExists($fileKey)) {
                $this->fileSystem->delete($fileKey);
            }
            $this->fileSystem->move($tmpKey, $fileKey);

            return true;
        } catch (FilesystemException $e) {
            $this->logger->error('File write/move failed', ['exception' => $e->getMessage(), 'fileKey' => $fileKey, 'tmpKey' => $tmpKey]);
            try {
                if ($this->fileSystem->fileExists($tmpKey)) {
                    $this->fileSystem->delete($tmpKey);
                }
            } catch (\Throwable) {
            }

            return false;
        } finally {
            if (\is_resource($in)) {
                fclose($in);
            }
            if (is_file($tmpPath) && !@unlink($tmpPath)) {
                $this->logger->warning('Failed to delete temp file', ['tmp' => $tmpPath]);
            }
        }
    }

    private function ftpMkdirRecursive(FtpClient $ftp, string $path): void
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn ($p) => $p !== ''));
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            try {
                if (!$ftp->isDir($current)) {
                    $ftp->mkdir($current, true);
                }
            } catch (FtpException $e) {
                $this->logger->error('Failed to create folder in Listrak FTP server', ['exception' => $e->getMessage()]);
            }
        }
    }
}
