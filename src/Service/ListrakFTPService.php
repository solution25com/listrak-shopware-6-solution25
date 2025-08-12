<?php

declare(strict_types=1);

namespace Listrak\Service;

use FtpClient\FtpClient;
use FtpClient\FtpException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

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

    public function exportToFTP($local, $tmp, $salesChannelContext): void
    {
        $user = $this->listrakConfigService->getConfig('ftpUsername', $salesChannelContext->getSalesChannelId());

        $pass = $this->listrakConfigService->getConfig('ftpPassword', $salesChannelContext->getSalesChannelId());
        $formattedDate = $this->formatDate();
        $remote = 'Listrak_Product_Feed_' . $formattedDate . '_' . $salesChannelContext->getSalesChannelId() . '.txt';

        if ($local) {
            $this->generateLocalFile($remote, $tmp);
        } else {
            $ftp = new FtpClient();

            try {
                $this->logger->debug(
                    'Trying to connect to Listrak FTP Server',
                    ['host' => self::HOST, 'port' => self::PORT, 'user' => $user, 'pass' => $pass]
                );
                $ftp->connect(self::HOST, false, self::PORT);
                $ftp->login($user, $pass);
                $ftp->pasv(true);
                $this->logger->debug(
                    'Connected to Listrak FTP Server',
                    ['host' => self::HOST, 'port' => self::PORT, 'user' => $user, 'pass' => $pass]
                );

                $remoteDir = rtrim(\dirname($remote), '/');
                if ($remoteDir !== '' && $remoteDir !== '.') {
                    $this->ftpMkdirRecursive($ftp, $remoteDir);
                }

                $ftp->put($remote, $tmp, FTP_BINARY);
                $this->logger->debug('File successfully exported to Listrak FTP Server', ['tmp' => $tmp]);

                $ftp->close();
            } catch (FtpException $e) {
                $this->logger->error('Failed to connect to FTP server: ' . $e->getMessage());
            } catch (\Throwable $e) {
                $this->logger->error('Something went wrong: ' . $e->getMessage());
            }
        }
    }

    public function generateLocalFile($fileName, $tmp): void
    {
        try {
            $tmpContent = file_get_contents($tmp);

            if ($this->fileSystem->fileExists($fileName)) {
                $this->fileSystem->delete($fileName);
            }

            $this->fileSystem->write($fileName, $tmpContent);
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage());
        }
    }

    private function formatDate(): string
    {
        $originDate = (new \DateTimeImmutable())->format('Ymd');
        $sequence = 1;                                                    // run number
        $version = 1;                                                    // could be same as $sequence
        $generatedAt = (new \DateTimeImmutable())->format('YmdHis');

        return \sprintf('%s%d_%d_%s', $originDate, $sequence, $version, $generatedAt);
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
                $this->logger->error($e->getMessage());
            }
        }
    }
}
