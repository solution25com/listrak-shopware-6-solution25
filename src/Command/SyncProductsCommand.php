<?php

declare(strict_types=1);

namespace Listrak\Command;

use Listrak\Message\SyncProductsMessage;
use Listrak\Service\ListrakConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextRestorer;
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
        private readonly EntityRepository $customerRepository,
        private readonly ListrakConfigService $listrakConfigService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
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
        $context = new Context(new SystemSource());
        $criteria = new Criteria();
        $salesChannelId = $input->getArgument('sales-channel-id');
        $limit = filter_var(
            $input->getOption('limit'),
            \FILTER_VALIDATE_INT,
            ['options' => ['default' => 2000, 'min_range' => 1]]
        );
        $local = $input->getOption('local');
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->setLimit(1);
        $customerIds = $this->customerRepository->searchIds($criteria, $context)->getIds();
        if (empty($customerIds)) {
            $output->writeln(
                '<error>Listrak product sync has been skipped. No sales channel context restorer found.</error>'
            );

            return Command::FAILURE;
        }
        $restorerId = $customerIds[0];

        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($restorerId, $context);

        $ftpUser = $this->listrakConfigService->getConfig(
            'ftpUsername',
            $salesChannelContext->getSalesChannel()->getId()
        );
        $ftpPassword = $this->listrakConfigService->getConfig(
            'ftpPassword',
            $salesChannelContext->getSalesChannel()->getId()
        );
        if ((!$ftpUser || !$ftpPassword) && !$local) {
            $output->writeln('<error>Listrak product sync has been skipped. The FTP credentials are missing.</error>');

            return Command::FAILURE;
        }
        $this->messageBus->dispatch(
            new SyncProductsMessage($local, $limit, $restorerId, $salesChannelContext->getSalesChannelId())
        );
        $this->logger->debug(
            'Product sync has been dispatched to queue',
            ['salesChannelId' => $salesChannelId]
        );
        $output->writeln('<info>Listrak product sync has been dispatched to the queue for the specified sales channel</info>');

        return Command::SUCCESS;
    }
}
