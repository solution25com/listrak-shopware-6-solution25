<?php

declare(strict_types=1);

namespace Listrak\ScheduledTask;

use Listrak\Service\FailedRequestService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: RequestRetryTask::class)]
class RequestRetryTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        protected EntityRepository $scheduledTaskRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly FailedRequestService $failedRequestService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    /**
     * @return iterable<class-string>
     */
    public static function getHandledMessages(): iterable
    {
        return [RequestRetryTask::class];
    }

    public function run(): void
    {
        $context = Context::createCLIContext();
        $criteria = new Criteria();
        $criteria->addFields(['id']);
        /** @var list<string> $salesChannelIds Sales-channel IDs have a single UUID primary key in every 6.7 release. */
        $salesChannelIds = $this->salesChannelRepository->searchIds($criteria, $context)->getIds();
        foreach ($salesChannelIds as $salesChannelId) {
            try {
                $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId);
                $this->failedRequestService->retry($salesChannelContext);
            } catch (\Throwable $exception) {
                $this->logger->error('Listrak retry failed for sales channel', [
                    'salesChannelId' => $salesChannelId,
                    'exceptionClass' => $exception::class,
                ]);
            }
        }
    }
}
