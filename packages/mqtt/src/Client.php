<?php

namespace Utopia\Mqtt;

use Swoole\Coroutine\Client as SwooleClient;

class Client
{
    private SwooleClient $client;

    private bool $connected = false;

    private string $host;

    private int $port;

    private bool $ssl;

    private float $timeout;

    private ?\Closure $onOpen = null;

    private ?\Closure $onReceive = null;

    private ?\Closure $onClose = null;

    private ?\Closure $onError = null;

    /**
     * @param string $url mqtt://host[:port] or mqtts://host[:port]
     * @param array{timeout?: float} $options
     */
    public function __construct(string $url, array $options = [])
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['host'])) {
            throw new \InvalidArgumentException('Invalid MQTT URL');
        }

        $this->ssl = ($parsedUrl['scheme'] ?? 'mqtt') === 'mqtts';
        $this->host = $parsedUrl['host'];
        $this->port = $parsedUrl['port'] ?? ($this->ssl ? 8883 : 1883);
        $this->timeout = $options['timeout'] ?? 30;
    }

    public function connect(): void
    {
        $this->client = new SwooleClient(SWOOLE_SOCK_TCP | ($this->ssl ? SWOOLE_SSL : 0));
        $this->client->set([
            'open_mqtt_protocol' => true,
            'timeout' => $this->timeout,
        ]);

        if (!$this->client->connect($this->host, $this->port, $this->timeout)) {
            $error = new \RuntimeException(
                "MQTT connection failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
            $this->emit('error', $error);
            throw $error;
        }

        $this->connected = true;
        $this->emit('open');
    }

    /** Send one already-encoded MQTT packet. */
    public function send(string $data): void
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected to MQTT broker');
        }

        $result = $this->client->send($data);

        if ($result === false) {
            $error = new \RuntimeException(
                "Failed to send data: {$this->client->errCode} - {$this->client->errMsg}"
            );
            $this->emit('error', $error);
            throw $error;
        }
    }

    /**
     * Block for the next framed MQTT packet. Returns the raw bytes, or null on a
     * receive timeout (the connection stays open) or once the connection is closed.
     */
    public function receive(): ?string
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected to MQTT broker');
        }

        $data = $this->client->recv($this->timeout);

        // '' is a clean peer close (EOF).
        if ($data === '') {
            $this->handleClose();
            return null;
        }

        // false is either a receive timeout (the connection stays open) or a terminal
        // socket error such as a connection reset (report it and tear the connection down).
        if ($data === false) {
            if ($this->timedOut()) {
                return null;
            }
            // Tear the connection down even if the error handler throws.
            try {
                $this->emit('error', new \RuntimeException(
                    "Failed to receive data: {$this->client->errCode} - {$this->client->errMsg}"
                ));
            } finally {
                $this->handleClose();
            }
            return null;
        }

        // A throwing receive handler is reported once, then the connection closes.
        try {
            $this->emit('receive', $data);
        } catch (\Throwable $error) {
            try {
                $this->emit('error', $error);
            } finally {
                $this->handleClose();
            }
            return null;
        }

        return $data;
    }

    /** Whether the last recv() returning false was a timeout rather than a socket error. */
    private function timedOut(): bool
    {
        $etimedout = defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110;

        return $this->client->errCode === $etimedout;
    }

    /** Loop receiving packets, emitting onReceive, until the connection closes. */
    public function listen(): void
    {
        while ($this->connected) {
            $data = $this->client->recv($this->timeout);

            // '' is a peer close; false is a timeout (keep listening) or a socket error.
            if ($data === '') {
                $this->handleClose();
                break;
            }

            if ($data === false) {
                if ($this->timedOut()) {
                    continue;
                }
                // Tear the connection down even if the error handler throws.
                try {
                    $this->emit('error', new \RuntimeException(
                        "Failed to receive data: {$this->client->errCode} - {$this->client->errMsg}"
                    ));
                } finally {
                    $this->handleClose();
                }
                break;
            }

            // A throwing receive handler is reported once, then the connection closes.
            try {
                $this->emit('receive', $data);
            } catch (\Throwable $error) {
                try {
                    $this->emit('error', $error);
                } finally {
                    $this->handleClose();
                }
                break;
            }
        }
    }

    public function close(): void
    {
        $this->handleClose();
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function onOpen(\Closure $callback): self
    {
        $this->onOpen = $callback;
        return $this;
    }

    public function onReceive(\Closure $callback): self
    {
        $this->onReceive = $callback;
        return $this;
    }

    public function onClose(\Closure $callback): self
    {
        $this->onClose = $callback;
        return $this;
    }

    public function onError(\Closure $callback): self
    {
        $this->onError = $callback;
        return $this;
    }

    private function handleClose(): void
    {
        if (!$this->connected) {
            return;
        }

        $this->connected = false;

        // Close the socket even if the close handler throws.
        try {
            $this->emit('close');
        } finally {
            $this->client->close();
        }
    }

    private function emit(string $event, mixed $data = null): void
    {
        $handler = match ($event) {
            'open' => $this->onOpen,
            'receive' => $this->onReceive,
            'close' => $this->onClose,
            'error' => $this->onError,
            default => null,
        };

        if ($handler !== null) {
            $handler($data);
        }
    }
}
