<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

use Swoole\Runtime;

/**
 * Tells, without waiting and without consuming anything, whether a read on a
 * stream socket would return at once: data has arrived, or the peer has closed
 * or reset the connection.
 */
final class Probe
{
    private const int READ_BUFFER = 8192;

    private ?\Socket $socket = null;

    private bool $imported = false;

    /**
     * Construct it before enabling TLS on the stream: socket_import_stream()
     * refuses a stream with TLS active, and the peek needs only the descriptor.
     *
     * @param resource $stream
     */
    public function __construct(private readonly mixed $stream)
    {
        $this->socket();
    }

    public function ready(): bool
    {
        if (stream_get_meta_data($this->stream)['unread_bytes'] > 0) {
            return true;
        }

        $socket = $this->socket();
        if ($socket instanceof \Socket) {
            return self::peek($socket);
        }

        // A peek rather than stream_select(): select() fails outright on a
        // descriptor numbered at or above FD_SETSIZE.
        stream_set_blocking($this->stream, false);
        try {
            return @stream_socket_recvfrom($this->stream, 1, STREAM_PEEK) !== false;
        } finally {
            stream_set_blocking($this->stream, true);
        }
    }

    private function socket(): ?\Socket
    {
        if (self::hooked()) {
            return null;
        }
        if (!$this->imported) {
            $this->imported = true;
            $this->socket = self::import($this->stream);
        }

        return $this->socket;
    }

    /** @param resource $stream */
    private static function import(mixed $stream): ?\Socket
    {
        if (!\function_exists('socket_import_stream') || !\defined('MSG_DONTWAIT')) {
            return null;
        }

        $socket = @socket_import_stream($stream);
        if (!$socket instanceof \Socket) {
            return null;
        }

        // socket_import_stream() turns the stream's read buffer off.
        stream_set_read_buffer($stream, self::READ_BUFFER);

        return $socket;
    }

    private static function peek(\Socket $socket): bool
    {
        $buffer = null;
        if (@socket_recv($socket, $buffer, 1, MSG_PEEK | MSG_DONTWAIT) !== false) {
            return true;
        }

        $error = socket_last_error($socket);
        socket_clear_error($socket);

        return !\in_array($error, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true);
    }

    /** Swoole's socket hooks replace socket_recv() with a coroutine read that ignores MSG_PEEK. */
    private static function hooked(): bool
    {
        return class_exists(Runtime::class, false) && (Runtime::getHookFlags() & SWOOLE_HOOK_SOCKETS) !== 0;
    }
}
