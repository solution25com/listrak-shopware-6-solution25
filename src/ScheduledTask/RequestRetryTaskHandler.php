<?php

declare(strict_types=1);

namespace Listrak\ScheduledTask;

use Listrak\Service\FailedRequestService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\SystemSource;
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
        $context = new Context(new SystemSource());
        $criteria = new Criteria();
        $criteria->addFields(['id']);
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
        if ($salesChannel) {
            $salesChannelContext = $this->salesChannelContextFactory->create(
                Uuid::randomHex(),
                $salesChannel['id'],
            );
            try {
                $this->logger->notice('RequestRetryTask started');

                $this->failedRequestService->retry($salesChannelContext);

                $this->logger->notice('RequestRetryTask ended');
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage() . ' ' . $exception->getTraceAsString());
            }
        }
    }
}
