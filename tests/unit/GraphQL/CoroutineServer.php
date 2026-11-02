<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL;

use Swoole\Coroutine;
use Utopia\DI\Container;
use Utopia\Http\Adapter;

final class CoroutineServer extends Adapter
{
    public const string CONTEXT_KEY = '__utopia__';

    public function __construct(private readonly Container $resources = new Container())
    {
    }

    public function onStart(callable $callback): void
    {
    }

    public function onRequest(callable $callback): void
    {
    }

    public function start(): void
    {
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        return Coroutine::getContext()[self::CONTEXT_KEY] ?? $this->resources;
    }
}
