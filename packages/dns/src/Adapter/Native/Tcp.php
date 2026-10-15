<?php

namespace Utopia\DNS\Adapter\Native;

use Exception;
use Socket;
use Utopia\DNS\Message;
use Utopia\DNS\Protocol;
use Utopia\DNS\ProxyProtocol;

class Tcp extends Transport
{
    protected ?Socket $listener = null;

    /** @var array<int, Socket> */
    protected array $clients = [];

    /** @var array<int, string> */
    protected array $buffers = [];

    /** @var array<int, int> Track last activity time per client for idle timeout */
    protected array $lastActivity = [];

    /** @var array<int, bool> Clients whose PROXY protocol header has not arrived yet */
    protected array $awaitingProxy = [];

    /** @var array<int, array{string, int}> Client addresses parsed from PROXY headers */
    protected array $peers = [];

    /**
     * @param string $host Host to bind to
     * @param int $port Port to listen on
     * @param int $maxClients Maximum concurrent TCP clients
     * @param int $maxBufferSize Maximum buffer size per client
     * @param int $maxFrameSize Maximum DNS message size over TCP
     * @param int $idleTimeout Seconds before idle connections are closed (RFC 7766)
     * @param bool $proxyProtocol Expect a PROXY protocol v1/v2 header on each
     *     connection (sent by load balancers to preserve the client address)
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 53,
        protected int $maxClients = 100,
        protected int $maxBufferSize = 16384,
        protected int $maxFrameSize = Message::MAX_SIZE,
        protected int $idleTimeout = 30,
        protected bool $proxyProtocol = false,
    ) {
        parent::__construct($host, $port);
    }

    public function bind(): void
    {
        $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$listener instanceof Socket) {
            throw new Exception('Could not create TCP socket.');
        }

        socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);

        if (socket_bind($listener, $this->host, $this->port) === false) {
            throw new Exception(\sprintf('Could not bind TCP socket to %s:%d.', $this->host, $this->port));
        }

        if (socket_listen($listener, 128) === false) {
            throw new Exception('Could not listen on TCP socket.');
        }

        socket_set_nonblock($listener);
        $this->listener = $listener;
    }

    public function getSockets(): array
    {
        $sockets = $this->listener instanceof Socket ? [$this->listener] : [];

        return [...$sockets, ...array_values($this->clients)];
    }

    public function onReadable(Socket $socket, callable $onPacket): void
    {
        if ($socket === $this->listener) {
            $this->accept($socket);
            return;
        }

        $this->handleClient($socket, $onPacket);
    }

    /**
     * Close idle connections per RFC 7766 Section 6.2.3.
     */
    public function tick(): void
    {
        $now = time();

        foreach ($this->clients as $id => $client) {
            if (($now - ($this->lastActivity[$id] ?? 0)) > $this->idleTimeout) {
                $this->close($client);
            }
        }
    }

    protected function accept(Socket $listener): void
    {
        $client = @socket_accept($listener);

        if (!$client instanceof Socket) {
            return;
        }

        if (\count($this->clients) >= $this->maxClients || @socket_set_nonblock($client) === false) {
            @socket_close($client);
            return;
        }

        socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);

        $id = spl_object_id($client);
        $this->clients[$id] = $client;
        $this->buffers[$id] = '';
        $this->lastActivity[$id] = time();

        if ($this->proxyProtocol) {
            $this->awaitingProxy[$id] = true;
        }
    }

    /**
     * @param callable(string $buffer, string $ip, int $port, Protocol $protocol): string $onPacket
     */
    protected function handleClient(Socket $client, callable $onPacket): void
    {
        $clientId = spl_object_id($client);

        $chunk = @socket_read($client, 8192, PHP_BINARY_READ);

        if ($chunk === '' || $chunk === false) {
            $error = socket_last_error($client);

            if ($chunk === '' || !\in_array($error, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true)) {
                $this->close($client);
            }

            return;
        }

        // Update activity timestamp for idle timeout tracking
        $this->lastActivity[$clientId] = time();

        if (\strlen($this->buffers[$clientId] ?? '') + \strlen($chunk) > $this->maxBufferSize) {
            $this->close($client);
            return;
        }

        $this->buffers[$clientId] = ($this->buffers[$clientId] ?? '') . $chunk;

        if ($this->awaitingProxy[$clientId] ?? false) {
            try {
                $header = ProxyProtocol::parse($this->buffers[$clientId]);
            } catch (\Throwable) {
                $this->close($client);
                return;
            }

            if (!$header instanceof \Utopia\DNS\ProxyProtocol) {
                return;
            }

            $this->buffers[$clientId] = substr($this->buffers[$clientId], $header->length);
            unset($this->awaitingProxy[$clientId]);

            if ($header->ip !== null && $header->port !== null) {
                $this->peers[$clientId] = [$header->ip, $header->port];
            }
        }

        while (\strlen($this->buffers[$clientId]) >= 2) {
            $unpacked = unpack('n', substr($this->buffers[$clientId], 0, 2));
            $payloadLength = (\is_array($unpacked) && \array_key_exists(1, $unpacked) && \is_int($unpacked[1])) ? $unpacked[1] : 0;

            // Close connection for invalid zero-length payloads, and enforce a
            // stricter frame limit than the 2-byte prefix allows to prevent
            // memory exhaustion from malicious clients
            if ($payloadLength === 0 || $payloadLength > $this->maxFrameSize) {
                $this->close($client);
                return;
            }

            if (\strlen($this->buffers[$clientId]) < ($payloadLength + 2)) {
                return;
            }

            $message = substr($this->buffers[$clientId], 2, $payloadLength);
            $this->buffers[$clientId] = substr($this->buffers[$clientId], $payloadLength + 2);

            $ip = '';
            $port = 0;
            if (isset($this->peers[$clientId])) {
                [$ip, $port] = $this->peers[$clientId];
            } else {
                socket_getpeername($client, $ip, $port);
            }

            if (\is_string($ip) && \is_int($port)) {
                $answer = \call_user_func($onPacket, $message, $ip, $port, Protocol::Tcp);

                if ($answer !== '') {
                    $this->respond($client, $answer);
                }
            }
        }
    }

    /**
     * Send a response with the 2-byte length prefix per RFC 1035 Section 4.2.2.
     * Oversized responses close the connection rather than send corrupted data.
     */
    protected function respond(Socket $client, string $payload): void
    {
        if (\strlen($payload) > Message::MAX_SIZE) {
            $this->close($client);
            return;
        }

        $frame = pack('n', \strlen($payload)) . $payload;
        $total = \strlen($frame);
        $sent = 0;

        while ($sent < $total) {
            $written = @socket_write($client, substr($frame, $sent));

            if ($written === false) {
                $error = socket_last_error($client);

                if (\in_array($error, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true)) {
                    socket_clear_error($client);
                    usleep(1000);
                    continue;
                }

                $this->close($client);
                return;
            }

            $sent += $written;
        }
    }

    protected function close(Socket $client): void
    {
        $id = spl_object_id($client);

        unset($this->clients[$id], $this->buffers[$id], $this->lastActivity[$id], $this->awaitingProxy[$id], $this->peers[$id]);

        @socket_close($client);
    }
}
