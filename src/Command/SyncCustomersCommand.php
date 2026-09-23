<?php

declare(strict_types=1);

namespace Listrak\Command;

use Doctrine\DBAL\Exception;
use Listrak\Message\SyncCustomersMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'listrak:sync-customers')]
class SyncCustomersCommand extends Command
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
        $this->setDescription('Syncs customers to Listrak for the specified sales channel');
        $this->addArgument(
            'sales-channel-id',
            InputArgument::REQUIRED,
            'Sales channel ID of the corresponding sales channel to sync customers for'
        );
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'The limit of customer entities to query', 500);
        $this->addOption('offset', null, InputOption::VALUE_OPTIONAL, 'The offset to start from', 0);
    }

    /**
     * @throws ExceptionInterface
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $salesChannelId = $input->getArgument('sales-channel-id');
        $offset = filter_var(
            $input->getOption('offset'),
            \FILTER_VALIDATE_INT,
            ['options' => ['default' => 0, 'min_range' => 0]]
        );
        $limit = filter_var(
            $input->getOption('limit'),
            \FILTER_VALIDATE_INT,
            ['options' => ['default' => 500, 'min_range' => 1]]
        );
        $clientId = $this->listrakConfigService->getConfig(
            'dataClientId',
            $salesChannelId
        );
        $clientSecret = $this->listrakConfigService->getConfig(
            'dataClientSecret',
            $salesChannelId
        );
        if (!$clientId || !$clientSecret) {
            $output->writeln('<error>Listrak customer sync has been skipped. The API keys are missing.</error>');

            return Command::FAILURE;
        }
        $this->messageBus->dispatch(
            new SyncCustomersMessage($offset, $limit, null, null, $salesChannelId)
        );
        $this->logger->debug(
            'Customer sync has been dispatched to queue',
            ['salesChannelId' => $salesChannelId]
        );

        $output->writeln('<info>Listrak customer sync has been dispatched to the queue for the specified sales channel.</info>');

        return Command::SUCCESS;
    }
}
