<?php

declare(strict_types=1);

namespace Utopia\Http;

use Utopia\DI\Container;
use Utopia\Telemetry\Adapter as Telemetry;

abstract class Adapter
{
    abstract public function onStart(callable $callback): void;
    abstract public function onRequest(callable $callback): void;
    abstract public function start(): void;

    /**
     * Receive the telemetry adapter so the server can publish its own runtime
     * metrics. No-op unless the adapter exposes runtime stats (see the Swoole
     * worker server).
     */
    public function setTelemetry(Telemetry $telemetry): void {}

    /**
     * Static resources container.
     *
     * Long-lived, shared across every request for the lifetime of the server.
     * Use this for things wired up at boot (config, clients, services) that
     * should be reused across requests. Available before any request begins,
     * inside server start hooks, and as the parent of every request context.
     */
    abstract public function resources(): Container;

    /**
     * Per-request context container.
     *
     * A fresh child container created for each incoming request and disposed
     * when the request ends. Use it to register or read request-scoped values
     * (request, response, route, error, ...). Lookups fall through to
     * {@see self::resources()}, so static resources remain reachable from
     * within request handlers. Outside of a request, this returns the static
     * resources container.
     */
    abstract public function context(): Container;
}
