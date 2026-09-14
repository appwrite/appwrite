<?php

namespace Utopia\Http\Adapter\SwooleCoroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server as SwooleServer;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\DI\Container;
use Utopia\Http\Adapter;
use Utopia\Http\TrustedHeaders;

class Server extends Adapter
{
    protected const string CONTEXT_KEY = '__utopia__';

    protected SwooleServer $server;

    /** @var callable|null */
    protected $onStartCallback;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        string $host,
        ?string $port = null,
        array $settings = [],
        protected Container $resources = new Container(),
        protected TrustedHeaders $trusted = new TrustedHeaders(),
    ) {
        $this->server = new SwooleServer($host, $port, false, true);
        $this->server->set($settings);
    }

    public function onRequest(callable $callback): void
    {
        $this->server->handle('/', function (SwooleRequest $request, SwooleResponse $response) use ($callback) {
            $context = new Container($this->resources);
            $context->set('swooleRequest', fn() => $request);
            $context->set('swooleResponse', fn() => $response);

            Coroutine::getContext()[self::CONTEXT_KEY] = $context;

            try {
                \call_user_func($callback, new Request($request, $this->trusted), new Response($response));
            } finally {
                unset(Coroutine::getContext()[self::CONTEXT_KEY]);
            }
        });
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        return Coroutine::getContext()[self::CONTEXT_KEY] ?? $this->resources;
    }

    public function getServer(): SwooleServer
    {
        return $this->server;
    }

    public function onStart(callable $callback): void
    {
        $this->onStartCallback = $callback;
    }

    public function start(): void
    {
        if ($this->onStartCallback) {
            \call_user_func($this->onStartCallback, $this);
        }

        $this->server->start();
    }
}
