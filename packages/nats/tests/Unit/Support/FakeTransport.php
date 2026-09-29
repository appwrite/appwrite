<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Support;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Transport\Transport;

/**
 * In-memory Transport double that emulates enough of the NATS wire protocol to
 * drive Connection through a full handshake without a real socket. It serves an
 * INFO line on connect and answers every PING with a PONG, records all bytes
 * written, and lets tests script additional inbound data.
 */
final class FakeTransport implements Transport
{
    public string $written = '';
    public ?\Closure $onWrite = null;
    /** @var list<string> */
    public array $writes = [];
    /** @var list<array<string, mixed>> */
    public array $tlsUpgrades = [];

    /**
     * Whether the server answers PINGs. Set false to emulate a server that has
     * gone away without closing the socket -- the case the stale-connection
     * budget exists for.
     */
    public bool $answerPings = true;

    private string $inbound = '';
    private bool $connected = false;

    /** @param array<string, mixed> $info Fields merged into the served INFO. */
    public function __construct(private readonly array $info = []) {}

    public function connect(string $host, int $port, float $timeout): void
    {
        $this->connected = true;
        $this->inbound .= $this->infoLine();
    }

    public function write(string $data): int
    {
        $this->written .= $data;
        $this->writes[] = $data;
        if ($this->onWrite instanceof \Closure) {
            ($this->onWrite)($data, $this);
        }

        // Answer PINGs so the handshake / flush / drain barrier completes.
        if ($this->answerPings) {
            $pings = substr_count($data, "PING\r\n");
            for ($i = 0; $i < $pings; $i++) {
                $this->inbound .= "PONG\r\n";
            }
        }

        return \strlen($data);
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        if ($this->inbound === '') {
            throw new TimeoutException('No inbound data');
        }

        $chunk = substr($this->inbound, 0, $maxBytes);
        $this->inbound = substr($this->inbound, \strlen($chunk));

        return $chunk;
    }

    public function readLine(?float $timeout = null): string
    {
        $pos = strpos($this->inbound, "\n");
        if ($pos === false) {
            throw new TimeoutException('No inbound line');
        }

        $line = substr($this->inbound, 0, $pos + 1);
        $this->inbound = substr($this->inbound, $pos + 1);

        return $line;
    }

    public function upgradeTls(array $options): void
    {
        $this->tlsUpgrades[] = $options;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    // --- Test helpers ---

    public function pushInbound(string $data): void
    {
        $this->inbound .= $data;
    }

    /**
     * Decode the CONNECT payload the client sent during the handshake.
     *
     * @return array<string, mixed>
     */
    public function connectPayload(): array
    {
        if (!preg_match('/CONNECT (\{.*?\})\r\n/', $this->written, $m)) {
            throw new ConnectionException('No CONNECT sent');
        }

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function infoLine(): string
    {
        $info = array_merge([
            'server_id' => 'FAKE',
            'server_name' => 'fake',
            'version' => '2.10.0',
            'proto' => 1,
            'host' => '127.0.0.1',
            'port' => 4222,
            'headers' => true,
            'auth_required' => false,
            'tls_required' => false,
            'tls_available' => false,
            'max_payload' => 1048576,
            'jetstream' => true,
        ], $this->info);

        return 'INFO ' . json_encode($info, JSON_THROW_ON_ERROR) . "\r\n";
    }
}
