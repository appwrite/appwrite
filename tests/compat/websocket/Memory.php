<?php

namespace Tests\Compat\Websocket;

use Swoole\Http\Request;
use Utopia\WebSocket\Adapter;

/**
 * The response an in-process request hook fills.
 */
final class Reply
{
    public int $status = 200;

    public string $body = '';
}

/**
 * An in-process adapter, the PHP twin of `utopia_websocket::Memory`: the
 * caller opens connections, delivers messages and requests, and reads back
 * the frames the server sent and the connections it closed.
 *
 * - start() calls onStart, then onWorkerStart for each worker (0 to the
 *   worker number, one by default); shutdown() calls onWorkerStop for each
 *   worker of a started server.
 * - send() records a frame for each open connection and skips the others.
 * - close() closes an open connection, records it and calls onClose.
 * - fail() makes the method behind a stage throw an \Exception.
 */
final class Memory extends Adapter
{
    public bool $started = false;

    /** @var array<int, true> */
    public array $open = [];

    /** @var list<array{0: int, 1: string}> */
    public array $frames = [];

    /** @var list<array{0: int, 1: int}> */
    public array $closed = [];

    /** @var array<string, string> */
    private array $failures = [];

    /** @var array<string, callable> */
    private array $hooks = [];

    public function fail(string $stage, string $message): void
    {
        $this->failures[$stage] = $message;
    }

    private function check(string $stage): void
    {
        if (isset($this->failures[$stage])) {
            throw new \Exception($this->failures[$stage]);
        }
    }

    private function hook(string $stage, callable $callback): self
    {
        $this->check($stage);
        $this->hooks[$stage] = $callback;

        return $this;
    }

    private function workers(): int
    {
        return (int) ($this->config['worker_num'] ?? 1);
    }

    public function open(int $connection, Request $request): void
    {
        $this->open[$connection] = true;
        if (isset($this->hooks['onOpen'])) {
            ($this->hooks['onOpen'])($connection, $request);
        }
    }

    public function message(int $connection, string $data): bool
    {
        if (!isset($this->open[$connection])) {
            return false;
        }
        if (isset($this->hooks['onMessage'])) {
            ($this->hooks['onMessage'])($connection, $data);
        }

        return true;
    }

    public function disconnect(int $connection): bool
    {
        if (!isset($this->open[$connection])) {
            return false;
        }
        unset($this->open[$connection]);
        if (isset($this->hooks['onClose'])) {
            ($this->hooks['onClose'])($connection);
        }

        return true;
    }

    public function request(Request $request): Reply
    {
        $reply = new Reply();
        if (isset($this->hooks['onRequest'])) {
            ($this->hooks['onRequest'])($request, $reply);
        }

        return $reply;
    }

    /**
     * @return array{package_max_length: int|null, websocket_compression: bool|null, worker_num: int|null}
     */
    public function settings(): array
    {
        return [
            'package_max_length' => $this->config['package_max_length'] ?? null,
            'websocket_compression' => $this->config['websocket_compression'] ?? null,
            'worker_num' => $this->config['worker_num'] ?? null,
        ];
    }

    public function start(): void
    {
        $this->check('start');
        $this->started = true;
        if (isset($this->hooks['onStart'])) {
            ($this->hooks['onStart'])();
        }
        if (isset($this->hooks['onWorkerStart'])) {
            for ($worker = 0; $worker < $this->workers(); $worker++) {
                ($this->hooks['onWorkerStart'])($worker);
            }
        }
    }

    public function shutdown(): void
    {
        $this->check('shutdown');
        $started = $this->started;
        $this->started = false;
        if ($started && isset($this->hooks['onWorkerStop'])) {
            for ($worker = 0; $worker < $this->workers(); $worker++) {
                ($this->hooks['onWorkerStop'])($worker);
            }
        }
    }

    public function send(array $connections, string $message): void
    {
        $this->check('send');
        foreach ($connections as $connection) {
            if (isset($this->open[$connection])) {
                $this->frames[] = [$connection, $message];
            }
        }
    }

    public function close(int $connection, int $code): void
    {
        $this->check('close');
        if (!isset($this->open[$connection])) {
            return;
        }
        unset($this->open[$connection]);
        $this->closed[] = [$connection, $code];
        if (isset($this->hooks['onClose'])) {
            ($this->hooks['onClose'])($connection);
        }
    }

    public function onStart(callable $callback): self
    {
        return $this->hook('onStart', $callback);
    }

    public function onWorkerStart(callable $callback): self
    {
        return $this->hook('onWorkerStart', $callback);
    }

    public function onWorkerStop(callable $callback): self
    {
        return $this->hook('onWorkerStop', $callback);
    }

    public function onOpen(callable $callback): self
    {
        return $this->hook('onOpen', $callback);
    }

    public function onMessage(callable $callback): self
    {
        return $this->hook('onMessage', $callback);
    }

    public function onRequest(callable $callback): self
    {
        return $this->hook('onRequest', $callback);
    }

    public function onClose(callable $callback): self
    {
        return $this->hook('onClose', $callback);
    }

    public function setPackageMaxLength(int $bytes): self
    {
        $this->config['package_max_length'] = $bytes;

        return $this;
    }

    public function setCompressionEnabled(bool $enabled): self
    {
        $this->config['websocket_compression'] = $enabled;

        return $this;
    }

    public function setWorkerNumber(int $num): self
    {
        $this->config['worker_num'] = $num;

        return $this;
    }

    public function getNative(): self
    {
        return $this;
    }
}
