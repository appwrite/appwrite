<?php

namespace Utopia\Cache\Adapter\Redis;

use Swoole\Coroutine\Client as SwooleClient;
use Throwable;

/**
 * Thin wrapper around a Swoole coroutine TCP client speaking Redis RESP2.
 *
 * Owns the socket and the unread byte buffer. Exposes a synchronous
 * request/response method for connection-time handshake (AUTH, SELECT) plus
 * raw send/recv for the asynchronous multiplexer that runs after handshake.
 */
class Client
{
    /**
     * Sentinel returned from parse() when the buffer does not yet hold a full frame.
     */
    public const INCOMPLETE = "\0__INCOMPLETE__\0";

    private readonly SwooleClient $client;

    private string $buffer = '';

    public function __construct(string $host, int $port, float $timeout)
    {
        $this->client = new SwooleClient(SWOOLE_SOCK_TCP);
        $this->client->set([
            'open_eof_check' => false,
            'package_max_length' => 64 * 1024 * 1024,
            'open_tcp_nodelay' => true,
        ]);

        if (! $this->client->connect($host, $port, $timeout)) {
            $errMsg = $this->client->errMsg;
            $this->close();

            throw new ConnectionException('Failed to connect to Redis: ' . $errMsg);
        }
    }

    /**
     * Synchronous command: encode, send, block until a frame arrives. Used
     * during connection handshake before the reader coroutine takes over.
     * Any extra bytes the kernel hands us after the frame stay in the
     * internal buffer and can be drained later via takeBuffer().
     *
     * @param  array<int|string>  $args
     */
    public function command(array $args, float $readTimeout): mixed
    {
        $this->send(self::encode($args));

        while (true) {
            $offset = 0;
            $value = self::parse($this->buffer, $offset);
            if ($value !== self::INCOMPLETE) {
                $this->buffer = substr($this->buffer, $offset);

                if ($value instanceof RedisError) {
                    throw $value->exception;
                }

                return $value;
            }

            $chunk = $this->client->recv($readTimeout);
            if ($chunk === false || $chunk === '') {
                throw new ConnectionException('Timed out waiting for Redis response');
            }
            $this->buffer .= $chunk;
        }
    }

    public function send(string $payload): void
    {
        $written = $this->client->send($payload);
        if ($written === \strlen($payload)) {
            return;
        }

        $message = $written === false
            ? ($this->client->errMsg ?: 'send failed')
            : 'partial send';

        throw new ConnectionException('Redis send failed: ' . $message);
    }

    public function recv(float $timeout): string|false
    {
        return $this->client->recv($timeout);
    }

    /**
     * Whether the last recv() returned false because its timeout elapsed, as
     * opposed to the peer closing or the socket failing.
     */
    public function timedOut(): bool
    {
        return $this->client->errCode === SOCKET_ETIMEDOUT;
    }

    /**
     * Drain and return any bytes the kernel delivered past the last parsed
     * frame. The reader coroutine seeds itself with these so handshake
     * leftovers are not lost.
     */
    public function takeBuffer(): string
    {
        $buffer = $this->buffer;
        $this->buffer = '';

        return $buffer;
    }

    public function close(): void
    {
        try {
            $this->client->close();
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * Recursively rethrow any RedisError / ConnectionError values produced by
     * parse() so callers see plain PHP types and exceptions. Arrays are walked
     * so that an error nested inside a multi-bulk reply still surfaces.
     */
    public static function unwrap(mixed $value): mixed
    {
        if ($value instanceof RedisError) {
            throw $value->exception;
        }

        if ($value instanceof ConnectionError) {
            throw $value->exception;
        }

        if (! \is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::unwrap($child);
        }

        return $value;
    }

    /**
     * Encode an array of arguments as a RESP array of bulk strings.
     *
     * @param  array<int|string>  $args
     */
    public static function encode(array $args): string
    {
        $out = '*' . \count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $out .= '$' . \strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        return $out;
    }

    /**
     * Parse a single RESP value from $buffer starting at $offset. Advances
     * $offset past the consumed bytes. Returns INCOMPLETE if not enough data
     * is present. Redis error frames are returned wrapped in RedisError.
     */
    public static function parse(string $buffer, int &$offset): mixed
    {
        if (! isset($buffer[$offset])) {
            return self::INCOMPLETE;
        }

        $type = $buffer[$offset];
        $lineEnd = strpos($buffer, "\r\n", $offset + 1);
        if ($lineEnd === false) {
            return self::INCOMPLETE;
        }

        $line = substr($buffer, $offset + 1, $lineEnd - $offset - 1);
        $offset = $lineEnd + 2;

        switch ($type) {
            case '+': // simple string
                return $line;
            case '-': // error
                return new RedisError(new \RedisException($line));
            case ':': // integer
                return (int) $line;
            case '$': // bulk string
                $len = (int) $line;
                if ($len === -1) {
                    return null;
                }
                if (\strlen($buffer) < $offset + $len + 2) {
                    return self::INCOMPLETE;
                }
                $value = substr($buffer, $offset, $len);
                $offset += $len + 2;

                return $value;
            case '*': // array
                $count = (int) $line;
                if ($count === -1) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $item = self::parse($buffer, $offset);
                    if ($item === self::INCOMPLETE) {
                        return self::INCOMPLETE;
                    }
                    $items[] = $item;
                }

                return $items;
            default:
                throw new \RedisException('Unknown RESP type: ' . $type);
        }
    }
}
