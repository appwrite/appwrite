<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Fakes;

use Psr\Http\Message\StreamInterface;
use Swoole\Coroutine;
use Utopia\Storage\Device\Local;

/**
 * A local store that can be slow, or fail on one write, and records what it
 * wrote in order.
 */
final class Store extends Local
{
    /** @var list<string> Paths written, in order */
    public array $writes = [];

    private int $attempts = 0;

    /**
     * @param float $delay Seconds each write takes
     * @param int $failAt The write (counted from 1) that throws; 0 for none
     */
    public function __construct(
        string $root,
        private readonly float $delay = 0.0,
        private readonly int $failAt = 0,
    ) {
        parent::__construct($root);
    }

    public function write(string $path, StreamInterface $data, string $contentType = ''): string
    {
        $attempt = ++$this->attempts;

        if ($this->delay > 0) {
            Coroutine::sleep($this->delay);
        }

        if ($attempt === $this->failAt) {
            throw new \RuntimeException('store down');
        }

        $this->writes[] = $path;

        return parent::write($path, $data, $contentType);
    }
}
