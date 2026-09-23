<?php

declare(strict_types=1);

namespace Listrak\Command;

use Listrak\Message\SyncProductsMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'listrak:sync-products')]
class SyncProductsCommand extends Command
{
    public function __construct(
        private readonly ListrakConfigService $listrakConfigService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Syncs products to Listrak for the specified sales channel');
        $this->addArgument(
            'sales-channel-id',
            InputArgument::REQUIRED,
            'Sales channel ID of the corresponding sales channel to sync products for'
        );
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'The limit of product entities to query', 2000);
        $this->addOption('local', null, InputOption::VALUE_NONE, 'Generate file locally instead of exporting to FTP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $salesChannelId = $input->getArgument('sales-channel-id');
        $limit = filter_var(
            $input->getOption('limit'),
            \FILTER_VALIDATE_INT,
            ['options' => ['default' => 2000, 'min_range' => 1]]
        );
        $local = $input->getOption('local');
        $ftpUser = $this->listrakConfigService->getConfig(
            'ftpUsername',
            $salesChannelId
        );
        $ftpPassword = $this->listrakConfigService->getConfig(
            'ftpPassword',
            $salesChannelId
        );
        if ((!$ftpUser || !$ftpPassword) && !$local) {
            $output->writeln('<error>Listrak product sync has been skipped. The FTP credentials are missing.</error>');

            return Command::FAILURE;
        }
        $this->messageBus->dispatch(
            new SyncProductsMessage($local, $limit, null, $salesChannelId)
        );
        $this->logger->debug(
            'Product sync has been dispatched to queue',
            ['salesChannelId' => $salesChannelId]
        );
        $output->writeln('<info>Listrak product sync has been dispatched to the queue for the specified sales channel</info>');

        return Command::SUCCESS;
    }
}
