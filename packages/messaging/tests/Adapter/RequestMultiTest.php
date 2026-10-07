<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;
use Utopia\Messaging\Adapter\Push;
use Utopia\Messaging\Messages\Push as PushMessage;
use Utopia\Psr7\Response;

final class RequestMultiTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSendsEveryRequestWhenThereAreMoreThanThePoolSize(): void
    {
        $client = new SlowClient();
        $adapter = new MultiAdapter(fn (): ClientInterface => $client);

        $results = $adapter->sendAll(60);

        $this->assertCount(60, $results);
        $this->assertSame(\range(0, 59), \array_column($results, 'index'));
        $this->assertSame(\array_fill(0, 60, 200), \array_column($results, 'statusCode'));
        $this->assertSame(\array_fill(0, 60, ''), \array_column($results, 'error'));
        $this->assertSame(60, $client->sent);
        $this->assertLessThanOrEqual(25, $client->peak);
    }
}

final class SlowClient implements ClientInterface
{
    public int $sent = 0;

    public int $active = 0;

    public int $peak = 0;

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->active++;
        $this->peak = \max($this->peak, $this->active);

        Coroutine::sleep(0.01);

        $this->active--;
        $this->sent++;

        return new Response(200);
    }
}

final class MultiAdapter extends Push
{
    public function __construct(\Closure $clientFactory)
    {
        parent::__construct(clientFactory: $clientFactory);
    }

    #[\Override]
    public function getName(): string
    {
        return 'multi';
    }

    #[\Override]
    public function getMaxMessagesPerRequest(): int
    {
        return 5000;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function sendAll(int $count): array
    {
        return $this->requestMulti(
            method: 'POST',
            urls: ['https://push.example.test/send'],
            headers: ['Content-Type: application/json'],
            bodies: \array_fill(0, $count, ['token' => 'token']),
        );
    }

    #[\Override]
    protected function process(PushMessage $message): array
    {
        return ['deliveredTo' => 0, 'type' => $this->getType(), 'results' => []];
    }
}
