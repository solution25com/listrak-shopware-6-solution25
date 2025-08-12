<?php

declare(strict_types=1);

namespace Listrak\Command;

use Listrak\Message\SyncOrdersMessage;
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

#[AsCommand(name: 'listrak:sync-orders')]
class SyncOrdersCommand extends Command
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
        $this->setDescription('Syncs orders to Listrak for all sales channels.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
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
            $clientId = $this->listrakConfigService->getConfig('dataClientId', $salesChannelContext->getSalesChannel()->getId());
            $clientSecret = $this->listrakConfigService->getConfig('dataClientSecret', $salesChannelContext->getSalesChannel()->getId());
            if (!$clientId || !$clientSecret) {
                $output->writeln('<info>Listrak order sync has been skipped. The API keys are missing.</info>');
                return Command::FAILURE;
            }
            $this->messageBus->dispatch(
                new SyncOrdersMessage(0, 200, null, $salesChannelContext->getSalesChannelId())
            );
        }
        $output->writeln('<info>Listrak order sync has been dispatched to the queue for every sales channel.</info>');

        return Command::SUCCESS;
    }
}
