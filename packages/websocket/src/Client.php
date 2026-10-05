<?php

namespace Utopia\WebSocket;

use Swoole\Coroutine\Http\Client as SwooleClient;
use Swoole\WebSocket\Frame;

class Client
{
    private SwooleClient $client;
    private bool $connected = false;
    private string $host;
    private int $port;
    private string $path;
    /** @var array<string, string> */
    private array $headers;
    private float $timeout;

    // Event handlers
    private ?\Closure $onMessage = null;
    private ?\Closure $onClose = null;
    private ?\Closure $onError = null;
    private ?\Closure $onOpen = null;
    private ?\Closure $onPing = null;
    private ?\Closure $onPong = null;

    /**
     * @param array{headers?: array<string, string>, timeout?: float} $options
     */
    public function __construct(string $url, array $options = [])
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            throw new \InvalidArgumentException('Invalid WebSocket URL');
        }

        if (!isset($parsedUrl['host'])) {
            throw new \InvalidArgumentException('WebSocket URL must contain a host');
        }

        $this->host = $parsedUrl['host'];
        $this->port = $parsedUrl['port'] ?? (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] === 'wss' ? 443 : 80);
        $this->path = $parsedUrl['path'] ?? '/';
        if (isset($parsedUrl['query'])) {
            $this->path .= '?' . $parsedUrl['query'];
        }

        $this->headers = $options['headers'] ?? [];
        $this->timeout = $options['timeout'] ?? 30;
    }

    public function connect(): void
    {
        $this->client = new SwooleClient($this->host, $this->port, $this->port === 443);
        $this->client->set([
            'timeout' => $this->timeout,
            'websocket_compression' => true,
            'max_frame_size' => 32 * 1024 * 1024, // 32MB max frame size
        ]);

        if ($this->headers !== []) {
            $this->client->setHeaders($this->headers);
        }

        $success = $this->client->upgrade($this->path);

        if (!$success) {
            $error = new \RuntimeException(
                "WebSocket connection failed: {$this->client->errCode} - {$this->client->errMsg}",
            );
            $this->emit('error', $error);
            throw $error;
        }

        $this->connected = true;
        $this->emit('open');
    }

    public function listen(): void
    {
        while ($this->connected) {
            try {
                $frame = $this->client->recv($this->timeout);

                if ($frame === false) {
                    if ($this->client->errCode === SWOOLE_ERROR_CLIENT_NO_CONNECTION) {
                        $this->handleClose();
                        break;
                    }
                    if ($this->client->errCode === 110) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "Failed to receive data: {$this->client->errCode} - {$this->client->errMsg}",
                    );
                }

                if ($frame === '') {
                    continue;
                }

                if ($frame instanceof Frame) {
                    $this->handleFrame($frame);
                }
            } catch (\Throwable $e) {
                $this->emit('error', $e);
                $this->handleClose();
                break;
            }
        }
    }

    private function handleFrame(Frame $frame): void
    {
        switch ($frame->opcode) {
            case WEBSOCKET_OPCODE_TEXT:
                $this->emit('message', $frame->data);
                break;
            case WEBSOCKET_OPCODE_CLOSE:
                $this->handleClose();
                break;
            case WEBSOCKET_OPCODE_PING:
                $this->emit('ping', $frame->data);
                $this->client->push('', WEBSOCKET_OPCODE_PONG);
                break;
            case WEBSOCKET_OPCODE_PONG:
                $this->emit('pong', $frame->data);
                break;
        }
    }

    private function handleClose(): void
    {
        if ($this->connected) {
            $this->connected = false;
            $this->emit('close');
            $this->client->close();
        }
    }

    public function send(string $data): void
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected to WebSocket server');
        }

        $success = $this->client->push($data);

        if ($success === false) {
            $error = new \RuntimeException(
                "Failed to send data: {$this->client->errCode} - {$this->client->errMsg}",
            );
            $this->emit('error', $error);
            throw $error;
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

    // Event handling methods
    public function onMessage(\Closure $callback): self
    {
        $this->onMessage = $callback;
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

    public function onOpen(\Closure $callback): self
    {
        $this->onOpen = $callback;
        return $this;
    }

    public function onPing(\Closure $callback): self
    {
        $this->onPing = $callback;
        return $this;
    }

    public function onPong(\Closure $callback): self
    {
        $this->onPong = $callback;
        return $this;
    }

    private function emit(string $event, mixed $data = null): void
    {
        $handler = match ($event) {
            'message' => $this->onMessage,
            'close' => $this->onClose,
            'error' => $this->onError,
            'open' => $this->onOpen,
            'ping' => $this->onPing,
            'pong' => $this->onPong,
            default => null
        };

        if ($handler instanceof \Closure) {
            $handler($data);
        }
    }

    public function receive(): ?string
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected to WebSocket server');
        }

        $frame = $this->client->recv($this->timeout);

        if ($frame === false) {
            if ($this->client->errCode === SWOOLE_ERROR_CLIENT_NO_CONNECTION) {
                $this->handleClose();
                return null;
            }
            throw new \RuntimeException(
                "Failed to receive data: {$this->client->errCode} - {$this->client->errMsg}",
            );
        }

        if ($frame === '') {
            return null;
        }

        if ($frame instanceof Frame) {
            switch ($frame->opcode) {
                case WEBSOCKET_OPCODE_TEXT:
                    $this->emit('message', $frame->data);
                    return $frame->data;
                case WEBSOCKET_OPCODE_CLOSE:
                    $this->handleClose();
                    return null;
                case WEBSOCKET_OPCODE_PING:
                    $this->emit('ping', $frame->data);
                    $this->client->push('', WEBSOCKET_OPCODE_PONG);
                    return null;
                case WEBSOCKET_OPCODE_PONG:
                    $this->emit('pong', $frame->data);
                    return null;
            }
        }

        return null;
    }
}
