<?php

declare(strict_types=1);

namespace Listrak\Command;

use Doctrine\DBAL\Exception;
use Listrak\Message\SyncCustomersMessage;
use Listrak\Service\ListrakConfigService;
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
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'listrak:sync-customers')]
class SyncCustomersCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly ListrakConfigService $listrakConfigService,
        private readonly SalesChannelContextRestorer $salesChannelContextRestorer,
        private readonly MessageBusInterface $messageBus,
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
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'The limit of customer entities to query');
        $this->addOption('offset', null, InputOption::VALUE_OPTIONAL, 'The offset to start from');
    }

    /**
     * @throws ExceptionInterface
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $salesChannelId = $input->getArgument('sales-channel-id');
        $offset = $input->getOption('offset') ?? 0;
        $limit = $input->getOption('limit') ?? 500;
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->setLimit(1);
        $customerIds = $this->customerRepository->searchIds($criteria, $context)->getIds();
        if (empty($customerIds)) {
            $output->writeln('<error>Listrak customer sync has been skipped. No sales channel context restorer found.</error>');

            return Command::FAILURE;
        }
        $restorerId = $customerIds[0];

        $salesChannelContext = $this->salesChannelContextRestorer->restoreByCustomer($restorerId, $context);
        $clientId = $this->listrakConfigService->getConfig(
            'dataClientId',
            $salesChannelContext->getSalesChannel()->getId()
        );
        $clientSecret = $this->listrakConfigService->getConfig(
            'dataClientSecret',
            $salesChannelContext->getSalesChannel()->getId()
        );
        if (!$clientId || !$clientSecret) {
            $output->writeln('<error>Listrak customer sync has been skipped. The API keys are missing.</error>');

            return Command::FAILURE;
        }
        $this->messageBus->dispatch(
            new SyncCustomersMessage($offset, $limit, null, $restorerId, $salesChannelContext->getSalesChannelId())
        );

        $output->writeln('<info>Listrak customer sync has been dispatched to the queue for the specified sales channel.</info>');

        return Command::SUCCESS;
    }
}
