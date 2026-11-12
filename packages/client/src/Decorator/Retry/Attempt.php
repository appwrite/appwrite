<?php

declare(strict_types=1);

namespace Utopia\Client\Decorator\Retry;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Psr7\Stream;

/**
 * One attempt of a streamed request: what reached the caller's sink, and the body
 * of an error response held back until the strategy decides whether to retry.
 */
final class Attempt
{
    public const int HELD_BODY_LIMIT = 65_536;

    private const int ERROR_STATUS = 400;

    private const int RELEASE_CHUNK_SIZE = 8_192;

    private int $delivered = 0;

    private ?StreamInterface $held = null;

    /**
     * @param Closure(string): void $sink
     */
    public function __construct(private readonly Closure $sink)
    {
    }

    public function observe(ResponseInterface $response): void
    {
        $this->close();

        if ($response->getStatusCode() >= self::ERROR_STATUS) {
            $this->held = new Stream();
        }
    }

    public function write(string $chunk): void
    {
        if (!$this->held instanceof StreamInterface) {
            $this->deliver($chunk);

            return;
        }

        if (($this->held->getSize() ?? 0) + \strlen($chunk) > self::HELD_BODY_LIMIT) {
            $this->release();
            $this->deliver($chunk);

            return;
        }

        $this->held->write($chunk);
    }

    public function deliver(string $chunk): void
    {
        $this->delivered += \strlen($chunk);
        ($this->sink)($chunk);
    }

    public function hasDelivered(): bool
    {
        return $this->delivered > 0;
    }

    public function withHeldBody(ResponseInterface $response): ResponseInterface
    {
        if (!$this->held instanceof StreamInterface) {
            return $response;
        }

        $this->held->rewind();

        return $response->withBody($this->held);
    }

    public function release(): void
    {
        $held = $this->held;
        if (!$held instanceof StreamInterface) {
            return;
        }

        $this->held = null;

        try {
            $held->rewind();
            while (!$held->eof()) {
                $chunk = $held->read(self::RELEASE_CHUNK_SIZE);
                if ($chunk !== '') {
                    $this->deliver($chunk);
                }
            }
        } finally {
            $held->close();
        }
    }

    public function close(): void
    {
        $this->held?->close();
        $this->held = null;
    }
}
