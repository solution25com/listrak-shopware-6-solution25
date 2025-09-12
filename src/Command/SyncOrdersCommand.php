<?php

declare(strict_types=1);

namespace Listrak\Command;

use Listrak\Message\SyncOrdersMessage;
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

#[AsCommand(name: 'listrak:sync-orders')]
class SyncOrdersCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly ListrakConfigService $listrakConfigService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Syncs orders to Listrak for the specified sales channel');
        $this->addArgument(
            'sales-channel-id',
            InputArgument::REQUIRED,
            'Sales channel ID of the corresponding sales channel to sync orders for'
        );
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'The limit of order entities to query', 500);
        $this->addOption('offset', null, InputOption::VALUE_OPTIONAL, 'The offset to start from', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = new Context(new SystemSource());
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
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->setLimit(1);
        $orderIds = $this->orderRepository->searchIds($criteria, $context)->getIds();
        if (empty($orderIds)) {
            $output->writeln('<error>Listrak order sync has been skipped. No sales channel context restorer found.</error>');

            return Command::FAILURE;
        }
        $restorerId = $orderIds[0];
        $salesChannelContext = $this->salesChannelContextRestorer->restoreByOrder($restorerId, $context);
        $clientId = $this->listrakConfigService->getConfig(
            'dataClientId',
            $salesChannelContext->getSalesChannel()->getId()
        );
        $clientSecret = $this->listrakConfigService->getConfig(
            'dataClientSecret',
            $salesChannelContext->getSalesChannel()->getId()
        );
        if (!$clientId || !$clientSecret) {
            $output->writeln('<error>Listrak order sync has been skipped. The API keys are missing.</error>');

            return Command::FAILURE;
        }
        $this->messageBus->dispatch(
            new SyncOrdersMessage($offset, $limit, null, $restorerId, $salesChannelContext->getSalesChannelId())
        );
        $this->logger->debug(
            'Order sync has been dispatched to queue',
            ['salesChannelId' => $salesChannelId]
        );
        $output->writeln('<info>Listrak order sync has been dispatched to the queue for the specified sales channel</info>');

        return Command::SUCCESS;
    }
}
