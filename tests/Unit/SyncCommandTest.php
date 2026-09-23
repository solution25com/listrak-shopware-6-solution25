<?php declare(strict_types=1);

namespace Listrak\Tests\Unit;

use Listrak\Service\ListrakConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncCommandTest extends TestCase
{
    public static function commands(): iterable
    {
        foreach (['Customers', 'Orders', 'Products', 'NewsletterRecipients'] as $entity) {
            yield $entity => ['Listrak\\Command\\Sync' . $entity . 'Command', 'Listrak\\Message\\Sync' . $entity . 'Message'];
        }
    }

    #[DataProvider('commands')]
    public function testExportCommandDispatchesForItsChannelWithoutACustomerOrOrder(string $commandClass, string $messageClass): void
    {
        $channel = Uuid::randomHex();
        $config = $this->createMock(ListrakConfigService::class);
        $config->method('getConfig')->willReturn('fixture');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($channel, $messageClass): Envelope {
            self::assertInstanceOf($messageClass, $message);
            self::assertSame($channel, $message->getSalesChannelId());
            self::assertNull($message->getRestorerId());
            self::assertSame(10, $message->getLimit());
            return new Envelope($message);
        });
        $tester = new CommandTester(new $commandClass($config, $bus, new NullLogger()));
        self::assertSame(Command::SUCCESS, $tester->execute(['sales-channel-id' => $channel, '--limit' => 10]));
    }
}
