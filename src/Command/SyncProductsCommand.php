<?php

declare(strict_types=1);

namespace Listrak\Command;

use Composer\Console\Input\InputOption;
use Listrak\Message\SyncProductsMessage;
use Listrak\Service\ListrakConfigService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'listrak:sync-products')]
class SyncProductsCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly ListrakConfigService $listrakConfigService,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Syncs products to Listrak for all sales channels.');
        $this->addOption('local', 'm', InputOption::VALUE_NONE, 'Generate file locally instead of exporting to FTP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $local = $input->getOption('local');
        $salesChannels = $this->salesChannelRepository->search($criteria, $context);
        $salesChannelContexts = [];
        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            $salesChannelContexts[] = $this->salesChannelContextFactory->create(
                Uuid::randomHex(),
                $salesChannel->getId(),
            );
        }
        /** @var SalesChannelContext $salesChannelContext */
        foreach ($salesChannelContexts as $salesChannelContext) {
            $ftpUser = $this->listrakConfigService->getConfig('ftpUsername', $salesChannelContext->getSalesChannel()->getId());
            $ftpPassword = $this->listrakConfigService->getConfig('ftpPassword', $salesChannelContext->getSalesChannel()->getId());
            if (!$ftpUser || !$ftpPassword) {
                $output->writeln('<info>Listrak product sync has been skipped. The FTP credentials are missing.</info>');
                return Command::FAILURE;
            }
            $this->messageBus->dispatch(
                new SyncProductsMessage($local, 0, 200, $salesChannelContext->getSalesChannelId())
            );
        }
        $output->writeln('<info>Listrak product sync has been dispatched to the queue for every sales channel.</info>');

        return Command::SUCCESS;
    }
}
