<?php

declare(strict_types=1);

namespace Listrak\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Listrak\Core\Content\FailedRequest\FailedRequestEntity;
use Listrak\Service\FailedRequestService;
use Listrak\Service\ListrakApiService;
use Listrak\Service\ListrakConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class ListrakApiServiceTest extends TestCase
{
    private const ENDPOINT = ['method' => 'POST', 'url' => 'https://api.listrak.com/data/v1/Customer/'];

    public function testTokensAreIsolatedByChannelAndIntegration(): void
    {
        $history = [];
        $queue = $this->createMock(FailedRequestService::class);
        $api = $this->api([$this->token('data-a'), $this->token('data-b'), $this->token('email-a')], $queue, $history);
        self::assertSame('data-a', $api->getAccessToken('DATA', $this->context('a')));
        self::assertSame('data-b', $api->getAccessToken('DATA', $this->context('b')));
        self::assertSame('email-a', $api->getAccessToken('EMAIL', $this->context('a')));
        self::assertSame('data-a', $api->getAccessToken('DATA', $this->context('a')));
        self::assertCount(3, $history);
        self::assertStringContainsString('client_id=b-dataClientId', (string) $history[1]['request']->getBody());
        self::assertStringContainsString('client_id=a-emailClientId', (string) $history[2]['request']->getBody());
    }

    public function testHttp401RefreshesOnceWithoutQueuingSuccessfulRequest(): void
    {
        $history = [];
        $queue = $this->createMock(FailedRequestService::class);
        $queue->expects(self::never())->method('saveRequestToFailedRequests');
        $queue->expects(self::never())->method('updateFailedRequest');
        $queue->expects(self::once())->method('removeFromFailedRequests');
        $api = $this->api([$this->token('expired'), new Response(401, [], '{}'), $this->token('fresh'), new Response(200, [], '{"ok":true}')], $queue, $history);
        self::assertSame('{"ok":true}', $api->authorizedRequest(self::ENDPOINT, ['body' => '{}'], $this->context('a'), 'DATA'));
        self::assertCount(4, $history);
        self::assertSame('Bearer expired', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer fresh', $history[3]['request']->getHeaderLine('Authorization'));
    }

    public function testPersistent401RecordsExactlyOneBusinessFailureWithoutCredentials(): void
    {
        $history = [];
        $queue = $this->createMock(FailedRequestService::class);
        $ctx = $this->context('a');
        $queue->expects(self::once())->method('saveRequestToFailedRequests')->with(
            self::ENDPOINT['url'], 'POST', ['body' => '{"customers":[]}', 'headers' => []], 'HTTP 401', $ctx, 'DATA'
        );
        $api = $this->api([$this->token('one'), new Response(401), $this->token('two'), new Response(401)], $queue, $history);
        $api->authorizedRequest(self::ENDPOINT, ['body' => '{"customers":[]}', 'headers' => ['authorization' => 'Bearer stale']], $ctx, 'DATA');
        self::assertCount(4, $history);
    }

    public function testFailedAuthenticationQueuesBusinessPayloadInsteadOfClientSecret(): void
    {
        $history = [];
        $queue = $this->createMock(FailedRequestService::class);
        $queue->expects(self::once())->method('saveRequestToFailedRequests')->with(
            self::ENDPOINT['url'], 'POST', ['body' => '{"customers":[]}', 'headers' => []],
            'Authentication or transport failure', self::anything(), 'DATA'
        );
        $api = $this->api([new Response(401, [], '{"error":"invalid_client"}')], $queue, $history);
        self::assertNull($api->authorizedRequest(self::ENDPOINT, ['body' => '{"customers":[]}'], $this->context('a'), 'DATA'));
        self::assertCount(1, $history);
    }

    public function testRetryUsesFreshAuthenticationAndDeletesSuccessfulQueueEntry(): void
    {
        $history = [];
        $failed = new FailedRequestEntity();
        $failed->setId(str_repeat('f', 32));
        $queue = $this->createMock(FailedRequestService::class);
        $ctx = $this->context('b');
        $queue->expects(self::once())->method('removeFromFailedRequests')->with($ctx, $failed);
        $queue->expects(self::never())->method('saveRequestToFailedRequests');
        $api = $this->api([$this->token('fresh-b'), new Response(204)], $queue, $history);
        $api->authorizedRequest(self::ENDPOINT, ['headers' => ['Authorization' => 'Bearer old-a']], $ctx, 'DATA', $failed);
        self::assertSame('Bearer fresh-b', $history[1]['request']->getHeaderLine('Authorization'));
    }

    public function testApiErrorInSuccessfulHttpResponseStillEntersRetryQueue(): void
    {
        $history = [];
        $queue = $this->createMock(FailedRequestService::class);
        $queue->expects(self::once())->method('saveRequestToFailedRequests');
        $queue->expects(self::never())->method('removeFromFailedRequests');
        $api = $this->api([$this->token('one'), new Response(200, [], '{"error":"invalid_request"}')], $queue, $history);
        $api->authorizedRequest(self::ENDPOINT, [], $this->context('a'), 'DATA');
    }

    private function api(array $responses, FailedRequestService $queue, array &$history): ListrakApiService
    {
        $config = $this->createMock(ListrakConfigService::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key, ?string $channel): string => $channel . '-' . $key);
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new ListrakApiService($config, $queue, new NullLogger(), new Client(['handler' => $stack]));
    }

    private function context(string $channel): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($channel);

        return $context;
    }

    private function token(string $token): Response
    {
        return new Response(200, [], json_encode(['access_token' => $token, 'expires_in' => 3600], JSON_THROW_ON_ERROR));
    }
}
