<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use ArrayObject;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;

final class SlowClient implements ClientInterface, StreamingClientInterface
{
    /**
     * @param ArrayObject<int, array{uri: string, rows: list<array<string, mixed>>}> $requests
     */
    public function __construct(
        private readonly ArrayObject $requests,
        private readonly float $latency,
    ) {
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        Coroutine::sleep($this->latency);

        $rows = [];
        foreach (\explode("\n", \trim((string) $request->getBody())) as $line) {
            $rows[] = \json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        $this->requests->append(['uri' => (string) $request->getUri(), 'rows' => $rows]);

        return new Response(200);
    }

    #[Override]
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->sendRequest($request);
    }
}
