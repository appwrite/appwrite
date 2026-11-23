<?php

declare(strict_types=1);

namespace Utopia\Audit\Tests\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * Records every request and answers from a queue of canned responses or failures.
 */
final class Client implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * @var list<ResponseInterface|Throwable>
     */
    private array $answers = [];

    public function respond(int $status, string $body = ''): self
    {
        $this->answers[] = new Response($status, body: new Stream($body));

        return $this;
    }

    public function fail(Throwable $error): self
    {
        $this->answers[] = $error;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $answer = array_shift($this->answers) ?? throw new RuntimeException('No response queued.');

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function last(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new RuntimeException('No request sent.')];
    }
}
