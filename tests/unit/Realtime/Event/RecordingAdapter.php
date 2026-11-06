<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Utopia\WebSocket\Adapter;

/** Records what the handler sends and closes, without a running server. */
final class RecordingAdapter extends Adapter
{
    /** @var list<array{array<int>, string}> */
    public array $sent = [];

    /** @var list<array{int, int}> */
    public array $closed = [];

    public function start(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function send(array $connections, string $message): void
    {
        $this->sent[] = [$connections, $message];
    }

    public function close(int $connection, int $code): void
    {
        $this->closed[] = [$connection, $code];
    }

    public function onStart(callable $callback): self
    {
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        return $this;
    }

    public function onWorkerStop(callable $callback): self
    {
        return $this;
    }

    public function onOpen(callable $callback): self
    {
        return $this;
    }

    public function onMessage(callable $callback): self
    {
        return $this;
    }

    public function onRequest(callable $callback): self
    {
        return $this;
    }

    public function onClose(callable $callback): self
    {
        return $this;
    }

    public function setPackageMaxLength(int $bytes): self
    {
        return $this;
    }

    public function setCompressionEnabled(bool $enabled): self
    {
        return $this;
    }

    public function setWorkerNumber(int $num): self
    {
        return $this;
    }

    public function getNative(): mixed
    {
        return null;
    }
}
