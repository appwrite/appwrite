<?php

declare(strict_types=1);

namespace Utopia\Queue\Internal;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Coalesce requests already waiting for the same transport, without a timer.
 * @internal
 */
final class Buffer
{
    private array $pending = [];
    private bool $running = false;

    /** @param \Closure(array, callable(int, mixed): void): void $send Confirms each request by index. */
    public function __construct(private readonly \Closure $send) {}

    public function request(mixed $request): mixed
    {
        if (!class_exists(Coroutine::class) || Coroutine::getCid() < 0) {
            $result = null;
            $this->dispatch([$request], static function (int $index, mixed $value) use (&$result): void {
                $result = $value;
            });
        } else {
            $reply = new Channel(1);
            $this->pending[] = [$request, $reply];
            if (!$this->running) {
                $this->running = true;
                Coroutine::create(function (): void {
                    try {
                        while ($this->pending !== []) {
                            $pending = array_splice($this->pending, 0, 1000);
                            $this->dispatch(array_column($pending, 0), static function (int $index, mixed $result) use ($pending): void {
                                $pending[$index][1]->push($result);
                            });
                        }
                    } finally {
                        $this->running = false;
                    }
                });
            }
            $result = $reply->pop();
            if ($reply->errCode !== 0) {
                throw new \RuntimeException('Queue confirmation wait was interrupted');
            }
        }

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    private function dispatch(array $requests, callable $resolved): void
    {
        $pending = array_fill_keys(array_keys($requests), true);
        try {
            ($this->send)($requests, static function (int $index, mixed $result) use (&$pending, $resolved): void {
                if (isset($pending[$index])) {
                    unset($pending[$index]);
                    $resolved($index, $result);
                }
            });
            $error = new \RuntimeException('Missing transport result');
        } catch (\Throwable $error) {
            // Earlier confirmations stand; only unresolved requests failed.
        }
        foreach (array_keys($pending) as $index) {
            $resolved($index, $error);
        }
    }

}
