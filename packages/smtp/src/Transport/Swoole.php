<?php

declare(strict_types=1);

namespace Utopia\SMTP\Transport;

use Swoole\Coroutine\Client;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Exception\TimeoutException;
use Utopia\SMTP\Tls;
use Utopia\SMTP\Verification;

/**
 * Coroutine-native. recv() and send() yield the scheduler on their own, so this
 * works even where the runtime hook for streams is switched off.
 *
 * Requires ext-swoole, which the package only suggests.
 */
final class Swoole implements Transport
{
    private ?Client $client = null;

    private bool $tls = false;

    /** Bytes taken off the socket but not yet handed out. */
    private string $buffer = '';

    /** The deadline currently configured for sends, which is not a per-call argument here. */
    private ?float $writeTimeout = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly Tls $options = new Tls(),
    ) {}

    public function connect(float $timeout, bool $tls): void
    {
        if ($tls) {
            $this->checkable();
        }

        $client = new Client(SWOOLE_SOCK_TCP | ($tls ? SWOOLE_SSL : 0));
        $client->set($this->settings($timeout));
        $started = microtime(true);

        if (! $client->connect($this->host, $this->port, $timeout)) {
            $reason = "Cannot reach {$this->host}:{$this->port}: " . $this->error($client);

            throw microtime(true) - $started >= $timeout
                ? new TimeoutException($reason)
                : new ConnectionException($reason);
        }

        $this->client = $client;
        $this->tls = $tls;
        $this->buffer = '';
        $this->writeTimeout = null;
    }

    public function read(int $length, float $timeout): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('A read needs a positive length');
        }

        if ($this->buffer === '') {
            $client = $this->client();
            $started = microtime(true);
            $data = $client->recv($timeout);

            if (! \is_string($data) || $data === '') {
                // SOCKET_ETIMEDOUT would say this outright, but it comes from
                // ext-sockets, which this package does not require and Swoole
                // does not provide. The clock is ours either way.
                throw microtime(true) - $started >= $timeout
                    ? new TimeoutException('Timed out waiting for the server')
                    : new ConnectionException('The server closed the connection: ' . $this->error($client));
            }

            $this->buffer = $data;
        }

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    public function write(string $data, float $timeout): void
    {
        $client = $this->client();

        // send() takes no deadline of its own, so the one asked for here has to
        // be configured on the client first. Left alone it would keep whatever
        // connect was given, and a write budget would quietly mean nothing.
        if ($this->writeTimeout !== $timeout) {
            $client->set(['write_timeout' => $timeout]);
            $this->writeTimeout = $timeout;
        }

        for ($written = 0; $written < \strlen($data);) {
            $started = microtime(true);
            $sent = $client->send(substr($data, $written));

            if (! \is_int($sent) || $sent < 1) {
                $reason = 'Failed writing to the server: ' . $this->error($client);

                throw microtime(true) - $started >= $timeout
                    ? new TimeoutException($reason)
                    : new ConnectionException($reason);
            }

            $written += $sent;
        }
    }

    public function startTls(float $timeout): void
    {
        $this->checkable();

        $client = $this->client();

        // enableSSL blocks until the handshake is done and takes no deadline
        // either, so the same applies.
        $client->set(['connect_timeout' => $timeout]);
        $started = microtime(true);

        if (! $client->enableSSL()) {
            $reason = "STARTTLS handshake with {$this->host} failed: " . $this->error($client);

            throw microtime(true) - $started >= $timeout
                ? new TimeoutException($reason)
                : new ConnectionException($reason);
        }

        $this->tls = true;
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function close(): void
    {
        if ($this->client instanceof Client) {
            $this->client->close();
            $this->client = null;
            $this->tls = false;
            $this->buffer = '';
            $this->writeTimeout = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(float $timeout): array
    {
        $settings = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'ssl_verify_peer' => $this->options->verify !== Verification::None,
            'ssl_allow_self_signed' => $this->options->verify !== Verification::Full,
            'ssl_host_name' => $this->options->peerName ?? $this->host,
        ];

        if ($this->options->caFile !== null) {
            $settings['ssl_cafile'] = $this->options->caFile;
        }

        if ($this->options->ciphers !== null) {
            $settings['ssl_ciphers'] = $this->options->ciphers;
        }

        return $settings;
    }

    /**
     * Swoole checks the name with X509_check_host(), which reads the DNS entries
     * of a certificate and not the address ones. Dialling an IP literal cannot
     * pass however the certificate is written — the stream transport checks
     * both — so saying so beats a handshake that fails with "SSL verify failed"
     * and nothing else.
     */
    private function checkable(): void
    {
        $name = $this->options->peerName ?? $this->host;

        if ($this->options->verify === Verification::None || filter_var($name, FILTER_VALIDATE_IP) === false) {
            return;
        }

        throw new ConnectionException(
            "This transport cannot check a certificate against the address {$name}. Give Tls a "
            . 'peerName the certificate carries, or ask for Verification::None.',
        );
    }

    /**
     * The extension declares errCode and errMsg untyped, so neither can be
     * dropped into a message without being looked at first.
     */
    private function error(Client $client): string
    {
        $code = $client->errCode;
        $message = $client->errMsg;

        return \sprintf(
            '[%d] %s',
            \is_int($code) ? $code : 0,
            \is_string($message) && $message !== '' ? $message : 'no reason given',
        );
    }

    private function client(): Client
    {
        if (! $this->client instanceof Client) {
            throw new \LogicException('The transport is not connected');
        }

        return $this->client;
    }
}
