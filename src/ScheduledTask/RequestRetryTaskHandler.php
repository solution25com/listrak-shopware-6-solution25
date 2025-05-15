<?php

declare(strict_types=1);

namespace Listrak\ScheduledTask;

use Listrak\Service\ListrakApiService;
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
     * @var LoggerInterface
     */
    private $logger;

    private ListrakApiService $listrakApiService;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        ListrakApiService $listrakApiService,
        LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->listrakApiService = $listrakApiService;
        $this->logger = $logger;
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
            $this->listrakApiService->retry($context);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . ' ' . $exception->getTraceAsString());
        }
    }
}
