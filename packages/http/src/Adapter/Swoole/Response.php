<?php

namespace Utopia\Http\Adapter\Swoole;

use Swoole\Coroutine;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Utopia\Http\Response as UtopiaResponse;

class Response extends UtopiaResponse
{
    /**
     * Swoole Response Object
     *
     * @var SwooleResponse
     */
    protected $swoole;

    /**
     * Bytes the server may hold for one connection before write() waits for
     * the client to read. In SWOOLE_PROCESS mode a write hands its bytes to the
     * reactor and returns at once, whatever the client is doing, and the
     * reactor queues them without bound (socket_buffer_size and send_yield do
     * not stop it), so without this a client that stops reading, such as a
     * paused video player, pins the whole response in memory until it
     * disconnects.
     */
    public static int $sendQueueLimit = 4 * 1024 * 1024;

    /**
     * Seconds write() waits for a client that reads nothing before it closes
     * the connection, like nginx's send_timeout.
     */
    public static float $sendTimeout = 60.0;

    /**
     * Seconds between checks of the connection's queue. The reactor counts a
     * write only once it has read it from the worker, a few milliseconds
     * later, so the first check also waits this long.
     */
    public static float $sendPollInterval = 0.01;

    /** Bytes written since the queue was last seen under the limit. */
    private int $unconfirmed = 0;

    /**
     * The connection this response first saw under its session id.
     *
     * @var array{mixed, mixed, mixed}|null
     */
    private ?array $connection = null;

    /** The server whose connection queues write() watches, if any. */
    private static ?SwooleServer $server = null;

    /**
     * Watch this server's connection queues, so write() waits for slow
     * clients instead of buffering without bound. One HTTP server runs in a
     * worker process, so this is set once, when it is created.
     */
    public static function setServer(?SwooleServer $server): void
    {
        self::$server = $server;
    }

    /**
     * Response constructor.
     */
    public function __construct(SwooleResponse $response)
    {
        $this->swoole = $response;
        parent::__construct(microtime(true));
    }

    public function getSwooleResponse(): SwooleResponse
    {
        return $this->swoole;
    }

    /**
     * Write
     *
     * @return bool False if write cannot complete, such as request ended by client
     */
    public function write(string $content): bool
    {
        if (!$this->swoole->write($content)) {
            return false;
        }

        $this->unconfirmed += \strlen($content);
        if ($this->unconfirmed < self::$sendQueueLimit) {
            return true;
        }

        return $this->drain();
    }

    /**
     * Bytes the server still holds for this connection, unsent: null when it
     * cannot tell, false when the connection is gone.
     *
     * @phpstan-impure
     */
    protected function queued(): int|false|null
    {
        if (self::$server === null) {
            return null;
        }

        // ignoreError: a connection that has gone is an answer here, not a warning.
        $info = self::$server->getClientInfo($this->swoole->fd, -1, true);
        if (!\is_array($info)) {
            return false;
        }

        // Session ids are reused once a connection closes: a different connection
        // under this one's id means this one is gone, and must not be waited on or closed.
        $connection = [$info['connect_time'] ?? null, $info['remote_ip'] ?? null, $info['remote_port'] ?? null];
        $this->connection ??= $connection;
        if ($connection !== $this->connection) {
            return false;
        }

        $queued = $info['send_queued_bytes'] ?? null;

        return \is_int($queued) ? $queued : null;
    }

    /**
     * Wait until the connection's queue is back under the limit, closing the
     * connection when the client reads nothing for the send timeout.
     *
     * @return bool False if the connection is gone or was closed for stalling
     */
    private function drain(): bool
    {
        // Waiting needs a coroutine and a server to ask; without them a write keeps its old behaviour.
        if (Coroutine::getCid() === -1 || $this->queued() === null) {
            return true;
        }

        $last = null;
        $stalled = 0.0;
        while (true) {
            Coroutine::sleep(self::$sendPollInterval);
            $stalled += self::$sendPollInterval;

            $queued = $this->queued();
            if ($queued === false) {
                return false;
            }
            if ($queued === null || $queued <= self::$sendQueueLimit) {
                $this->unconfirmed = 0;

                return true;
            }
            if ($last !== null && $queued < $last) {
                $stalled = 0.0;
            }
            if ($stalled >= self::$sendTimeout) {
                $this->swoole->close();

                return false;
            }
            $last = $queued;
        }
    }

    /**
     * End
     */
    public function end(?string $content = null): void
    {
        $this->swoole->end($content);
    }

    /**
     * Abort a partial response without sending the terminal HTTP chunk.
     */
    public function abort(): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;
        $this->disablePayload();
        $this->swoole->close();
    }

    /**
     * Send Status Code
     */
    protected function sendStatus(int $statusCode): void
    {
        $this->swoole->status((string) $statusCode);
    }

    /**
     * Send Header
     *
     * @param  array<int, string>  $value
     */
    public function sendHeader(string $key, array $value): void
    {
        $this->swoole->header($key, $value);
    }

    /**
     * Send Cookie
     *
     * Send a cookie
     *
     * @param  array<string, mixed>  $options
     */
    protected function sendCookie(string $name, string $value, array $options): void
    {
        // Coalesce nulls to the types Swoole's cookie() expects: the SameSite
        // argument is parsed as a string (Z_PARAM_STR), so it must not be a bool.
        $this->swoole->cookie(
            $name,
            $value,
            $options['expire'] ?? 0,
            $options['path'] ?? '',
            $options['domain'] ?? '',
            $options['secure'] ?? false,
            $options['httponly'] ?? false,
            $options['samesite'] ?? '',
        );
    }
}
