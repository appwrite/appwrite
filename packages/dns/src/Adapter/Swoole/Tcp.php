<?php

declare(strict_types=1);

namespace Utopia\DNS\Adapter\Swoole;

use Swoole\Server;
use Swoole\Server\Port;
use Utopia\DNS\Message;
use Utopia\DNS\Protocol;
use Utopia\DNS\ProxyProtocol;

class Tcp extends Transport
{
    /** @var array<int, string> Per-connection receive buffer (PROXY protocol mode only) */
    protected array $buffers = [];

    /** @var array<int, array{string, int}|null> Client address parsed from the PROXY header, null when it carried none */
    protected array $peers = [];

    /**
     * @param bool $proxyProtocol Expect a PROXY protocol v1/v2 header on each
     *     connection (sent by load balancers to preserve the client address)
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 53,
        protected bool $proxyProtocol = false,
    ) {
        parent::__construct($host, $port);
    }

    public function getSockType(): int
    {
        return SWOOLE_SOCK_TCP;
    }

    public function getSettings(): array
    {
        // The PROXY header precedes the first frame, so Swoole's length check
        // cannot be used; framing happens in attach() instead.
        if ($this->proxyProtocol) {
            return ['open_http_protocol' => false];
        }

        // Length-prefixed framing per RFC 1035 Section 4.2.2
        return [
            'open_http_protocol' => false,
            'open_length_check' => true,
            'package_length_type' => 'n',
            'package_length_offset' => 0,
            'package_body_offset' => 2,
            'package_max_length' => Message::MAX_SIZE + 2,
        ];
    }

    public function attach(Server|Port $target, callable $onPacket): void
    {
        if (!$this->proxyProtocol) {
            // Supports larger responses with length-prefixed framing per RFC 5966
            $target->on('Receive', function (Server $server, int $fd, int $reactorId, string $data) use ($onPacket): void {
                [$ip, $port] = $this->getClientAddress($server, $fd, $reactorId);
                $payload = substr($data, 2); // strip 2-byte length prefix

                $response = \call_user_func($onPacket, $payload, $ip, $port, Protocol::Tcp);

                if ($response !== '') {
                    $server->send($fd, pack('n', \strlen($response)) . $response);
                }
            });

            return;
        }

        $target->on('Receive', function (Server $server, int $fd, int $reactorId, string $data) use ($onPacket): void {
            $buffer = ($this->buffers[$fd] ?? '') . $data;

            if (!\array_key_exists($fd, $this->peers)) {
                try {
                    $header = ProxyProtocol::parse($buffer);
                } catch (\Throwable) {
                    $this->disconnect($server, $fd);
                    return;
                }

                if (!$header instanceof \Utopia\DNS\ProxyProtocol) {
                    $this->buffers[$fd] = $buffer;
                    return;
                }

                $buffer = substr($buffer, $header->length);
                $this->peers[$fd] = $header->ip !== null && $header->port !== null ? [$header->ip, $header->port] : null;
            }

            while (\strlen($buffer) >= 2) {
                $unpacked = unpack('n', substr($buffer, 0, 2));
                $length = (\is_array($unpacked) && \is_int($unpacked[1])) ? $unpacked[1] : 0;

                if ($length === 0 || $length > Message::MAX_SIZE) {
                    $this->disconnect($server, $fd);
                    return;
                }

                if (\strlen($buffer) < $length + 2) {
                    break;
                }

                $payload = substr($buffer, 2, $length);
                $buffer = substr($buffer, $length + 2);

                [$ip, $port] = $this->peers[$fd] ?? $this->getClientAddress($server, $fd, $reactorId);

                $response = \call_user_func($onPacket, $payload, $ip, $port, Protocol::Tcp);

                if ($response !== '') {
                    $server->send($fd, pack('n', \strlen($response)) . $response);
                }
            }

            $this->buffers[$fd] = $buffer;
        });

        $target->on('Close', function (Server $server, int $fd): void {
            unset($this->buffers[$fd], $this->peers[$fd]);
        });
    }

    /**
     * @return array{string, int}
     */
    protected function getClientAddress(Server $server, int $fd, int $reactorId): array
    {
        $info = $server->getClientInfo($fd, $reactorId);
        if (!\is_array($info)) {
            return ['', 0];
        }

        $ip = \is_string($info['remote_ip'] ?? null) ? $info['remote_ip'] : '';
        $port = \is_int($info['remote_port'] ?? null) ? $info['remote_port'] : 0;

        return [$ip, $port];
    }

    protected function disconnect(Server $server, int $fd): void
    {
        unset($this->buffers[$fd], $this->peers[$fd]);
        $server->close($fd);
    }
}
