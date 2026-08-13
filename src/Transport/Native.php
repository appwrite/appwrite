<?php

declare(strict_types=1);

namespace Utopia\SMTP\Transport;

use Utopia\SMTP\ConnectionException;
use Utopia\SMTP\Tls;
use Utopia\SMTP\Verification;

/**
 * Streams. Under Swoole's runtime hook these yield the scheduler like any other
 * hooked stream, so this transport is not limited to blocking contexts.
 */
final class Native implements Transport
{
    /** @var resource|null */
    private $stream;

    private bool $tls = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly Tls $options = new Tls(),
    ) {}

    public function connect(float $timeout, bool $tls): void
    {
        $context = stream_context_create(['ssl' => $this->ssl()]);

        $stream = @stream_socket_client(
            ($tls ? 'ssl' : 'tcp') . "://{$this->host}:{$this->port}",
            $code,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new ConnectionException("Cannot reach {$this->host}:{$this->port}: {$error}", $code ?? 0);
        }

        $this->stream = $stream;
        $this->tls = $tls;
        $this->timeout($timeout);
    }

    public function read(int $length, float $timeout): string
    {
        if ($length < 1) {
            throw new ConnectionException('A read needs a positive length');
        }

        $stream = $this->stream();
        $this->timeout($timeout);

        $data = @fread($stream, $length);

        if ($data === false || $data === '') {
            throw new ConnectionException(
                stream_get_meta_data($stream)['timed_out']
                    ? 'Timed out waiting for the server'
                    : 'The server closed the connection',
            );
        }

        return $data;
    }

    public function write(string $data, float $timeout): void
    {
        $stream = $this->stream();
        $this->timeout($timeout);

        for ($written = 0; $written < \strlen($data);) {
            $sent = @fwrite($stream, substr($data, $written));

            if ($sent === false || $sent === 0) {
                throw new ConnectionException('Failed writing to the server');
            }

            $written += $sent;
        }
    }

    public function startTls(float $timeout): void
    {
        $stream = $this->stream();
        $this->timeout($timeout);

        // STREAM_CRYPTO_METHOD_TLS_CLIENT means "TLS 1.0 or better" here, not
        // "TLS 1.0", so this does not pin the handshake to an obsolete version.
        $started = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($started !== true) {
            throw new ConnectionException("STARTTLS handshake with {$this->host} failed");
        }

        $this->tls = true;
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
            $this->tls = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ssl(): array
    {
        $options = [
            // An unknown issuer and the wrong hostname are different failures,
            // so SelfSigned forgives the first while still catching the second.
            'verify_peer' => $this->options->verify !== Verification::None,
            'verify_peer_name' => $this->options->verify !== Verification::None,
            'allow_self_signed' => $this->options->verify !== Verification::Full,
            'peer_name' => $this->options->peerName ?? $this->host,
            'SNI_enabled' => true,
        ];

        if ($this->options->caFile !== null) {
            $options['cafile'] = $this->options->caFile;
        }

        if ($this->options->ciphers !== null) {
            $options['ciphers'] = $this->options->ciphers;
        }

        return $options;
    }

    private function timeout(float $timeout): void
    {
        $seconds = (int) $timeout;

        stream_set_timeout($this->stream(), $seconds, (int) (($timeout - $seconds) * 1_000_000));
    }

    /**
     * @return resource
     */
    private function stream()
    {
        if ($this->stream === null) {
            throw new ConnectionException('The transport is not connected');
        }

        return $this->stream;
    }
}
