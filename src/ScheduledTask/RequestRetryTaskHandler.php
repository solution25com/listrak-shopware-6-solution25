<?php

declare(strict_types=1);

namespace Listrak\ScheduledTask;

use Listrak\Service\FailedRequestService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: RequestRetryTask::class)]
class RequestRetryTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        protected EntityRepository $scheduledTaskRepository,
        private FailedRequestService $failedRequestService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository);
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
        $context = Context::createDefaultContext();
        try {
            $this->logger->notice('Running Listrak request retry task');
            $this->failedRequestService->retry($context);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . ' ' . $exception->getTraceAsString());
        }
    }
}
