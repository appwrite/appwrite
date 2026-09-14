<?php

declare(strict_types=1);

namespace Utopia\Http\Adapter\FPM;

use Utopia\DI\Container;
use Utopia\Http\Adapter;
use Utopia\Http\TrustedHeaders;

class Server extends Adapter
{
    private ?Container $context = null;

    public function __construct(
        private Container $resources,
        private TrustedHeaders $trusted = new TrustedHeaders(),
    ) {}

    public function onRequest(callable $callback): void
    {
        $request = new Request($this->trusted);
        $response = new Response();

        $this->context = new Container($this->resources);
        $this->context->set('fpmRequest', fn() => $request);
        $this->context->set('fpmResponse', fn() => $response);

        try {
            \call_user_func($callback, $request, $response);
        } finally {
            $this->context = null;
        }
    }

    public function onStart(callable $callback): void
    {
        \call_user_func($callback, $this);
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        return $this->context ?? $this->resources;
    }

    public function start(): void {}
}
