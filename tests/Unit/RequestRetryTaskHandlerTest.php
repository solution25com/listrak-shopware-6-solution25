<?php

declare(strict_types=1);

namespace Listrak\Tests\Unit;

use Listrak\ScheduledTask\RequestRetryTaskHandler;
use Listrak\Service\FailedRequestService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class RequestRetryTaskHandlerTest extends TestCase
{
    public function testItProcessesEveryChannelAndContinuesAfterAnInvalidChannel(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturn(IdSearchResult::fromIds(
            ['broken', 'channel-a', 'channel-b'], new Criteria(), Context::createCLIContext()
        ));
        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->expects(self::exactly(3))->method('create')->willReturnCallback(function (string $token, string $id): SalesChannelContext {
            if ($id === 'broken') {
                throw new \RuntimeException('Deleted or incomplete channel');
            }
            $context = $this->createMock(SalesChannelContext::class);
            $context->method('getSalesChannelId')->willReturn($id);

            return $context;
        });
        $visited = [];
        $queue = $this->createMock(FailedRequestService::class);
        $queue->expects(self::exactly(2))->method('retry')->willReturnCallback(static function (SalesChannelContext $context) use (&$visited): void {
            $visited[] = $context->getSalesChannelId();
        });
        $handler = new RequestRetryTaskHandler($this->createMock(EntityRepository::class), $repository, $factory, $queue, new NullLogger());
        $handler->run();
        self::assertSame(['channel-a', 'channel-b'], $visited);
    }
}
