<?php

namespace Appwrite\Alert\Probe;

use Swoole\Coroutine\Client;
use Swoole\Coroutine\System;

/**
 * A TCP connection a probe opens, optionally over TLS, to an address the
 * egress allows. The host is resolved and checked before anything is dialled,
 * and every operation shares one deadline.
 */
final class Connection
{
    /** Bytes read but not yet taken as a line. */
    private string $buffer = '';

    private function __construct(
        private readonly Client $client,
        private readonly string $host,
        private readonly bool $verify,
        private readonly float $deadline,
    ) {
    }

    /**
     * @param bool $tls handshake on connect (implicit TLS)
     * @param bool $verify check the certificate chain and that it names the host
     * @param float $deadline microtime() by which the whole check must finish
     *
     * @throws Failure
     */
    public static function open(string $host, int $port, bool $tls, bool $verify, Egress $egress, float $deadline): self
    {
        $host = \trim($host, '[]');
        $address = self::address($host, $egress, $deadline);

        $client = new Client((\str_contains($address, ':') ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP) | ($tls ? SWOOLE_SSL : 0));
        $connection = new self($client, $host, $verify, $deadline);
        $client->set($connection->settings());

        if (!$client->connect($address, $port, $connection->remaining())) {
            $errno = (int) $client->errCode;
            throw new Failure(match (true) {
                $errno === SOCKET_ECONNREFUSED => Result::ConnectRefused,
                $errno === SOCKET_ETIMEDOUT || $connection->remaining() <= 0.001 => Result::ConnectTimeout,
                $tls && !\in_array($errno, [SOCKET_ENETUNREACH, SOCKET_EHOSTUNREACH, SOCKET_ECONNRESET], true) => Result::TlsFailed,
                default => Result::ConnectFailed,
            });
        }

        return $connection;
    }

    /**
     * One line, with its line ending removed, read within `$max` octets including that ending.
     *
     * @throws \LengthException When the line is longer
     * @throws Failure
     */
    public function line(int $max): string
    {
        while (($position = \strpos($this->buffer, "\n")) === false) {
            if (\strlen($this->buffer) >= $max) {
                throw new \LengthException("Line longer than {$max} octets");
            }
            $this->buffer .= $this->read();
        }

        if ($position + 1 > $max) {
            throw new \LengthException("Line longer than {$max} octets");
        }

        $line = \substr($this->buffer, 0, $position);
        $this->buffer = \substr($this->buffer, $position + 1);

        return \rtrim($line, "\r");
    }

    /**
     * @throws Failure
     */
    public function write(string $data): void
    {
        if ($this->client->send($data) === false) {
            throw new Failure($this->timedOut() ? Result::Timeout : Result::ConnectFailed);
        }
    }

    /**
     * Upgrades the connection in place. Anything already read arrived in the
     * clear, so it is dropped unread.
     *
     * @throws Failure
     */
    public function startTls(): void
    {
        $this->buffer = '';
        $this->client->set($this->settings());
        if (!$this->client->enableSSL()) {
            throw new Failure($this->timedOut() ? Result::Timeout : Result::TlsFailed);
        }
    }

    /**
     * When the peer's certificate expires, or null without TLS or a readable certificate.
     */
    public function certificateExpiresAt(): ?\DateTimeImmutable
    {
        $certificate = $this->client->getPeerCert();
        if (!\is_string($certificate) || $certificate === '') {
            return null;
        }

        $parsed = \openssl_x509_parse($certificate);
        if (!\is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return null;
        }

        return new \DateTimeImmutable('@' . (int) $parsed['validTo_time_t']);
    }

    public function close(): void
    {
        $this->client->close();
    }

    /**
     * @throws Failure
     */
    private function read(): string
    {
        $data = $this->client->recv($this->remaining());
        if (\is_string($data) && $data !== '') {
            return $data;
        }

        throw new Failure($data === false && $this->timedOut() ? Result::Timeout : Result::ConnectFailed);
    }

    private function timedOut(): bool
    {
        return (int) $this->client->errCode === SOCKET_ETIMEDOUT || $this->remaining() <= 0.001;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'timeout' => $this->remaining(),
            'ssl_host_name' => $this->host,
            'ssl_verify_peer' => $this->verify,
            'ssl_allow_self_signed' => !$this->verify,
        ];
    }

    private function remaining(): float
    {
        return \max(0.001, $this->deadline - \microtime(true));
    }

    /**
     * The address to dial: the host itself when it is one, or the first address
     * it resolves to that the egress allows.
     *
     * @throws Failure
     */
    private static function address(string $host, Egress $egress, float $deadline): string
    {
        $addresses = \filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [];
        if ($addresses === []) {
            foreach ([AF_INET, AF_INET6] as $family) {
                // getaddrinfo() needs a socket type and a service; any port works for the lookup
                $found = System::getaddrinfo($host, $family, SOCK_STREAM, STREAM_IPPROTO_TCP, '80', \max(0.001, $deadline - \microtime(true)));
                foreach (\is_array($found) ? $found : [] as $address) {
                    if (\is_string($address)) {
                        $addresses[] = $address;
                    }
                }
            }
        }

        if ($addresses === []) {
            throw new Failure(Result::DnsFailed);
        }

        foreach ($addresses as $address) {
            if ($egress->allows($address)) {
                return $address;
            }
        }

        throw new Failure(Result::TargetDenied);
    }
}
