<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class Transport implements ClientInterface
{
    /**
     * @var list<ResponseInterface|\Throwable>
     */
    private array $replies;

    /**
     * @var list<RequestInterface>
     */
    private array $requests = [];

    public function __construct(ResponseInterface|\Throwable ...$replies)
    {
        $this->replies = array_values($replies);
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $reply = array_shift($this->replies);
        if ($reply === null) {
            throw new \LogicException("No reply queued for {$request->getMethod()} {$request->getUri()}");
        }

        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return $reply;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function fixture(string $name, int $status = 200, array $headers = []): ResponseInterface
    {
        $path = __DIR__ . "/Store/Fixtures/{$name}.json";
        $body = is_file($path) ? file_get_contents($path) : false;
        if ($body === false) {
            throw new \LogicException("Fixture {$name} does not exist at {$path}");
        }

        $response = new Response($status, body: new Stream\Factory()->createStream($body))
            ->withHeader('Content-Type', 'application/json');

        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }
}
