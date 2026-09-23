<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Client\ClientInterface;
use Utopia\Psr18\StreamingClientInterface;

/**
 * A transport that can both buffer (PSR-18) and stream responses, configured
 * through immutable withX helpers. Decorators implement this too, so they compose.
 */
interface Adapter extends ClientInterface, StreamingClientInterface
{
    public function withTimeout(float $seconds): static;

    public function withConnectTimeout(float $seconds): static;

    public function withSslVerification(bool $enabled = true): static;

    public function withCustomCA(string $path): static;

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static;

    public function withMinTlsVersion(Tls $version): static;

    /**
     * Reuse the underlying connection across requests to the same origin so the
     * TCP/TLS handshake is paid once, rather than dialling afresh every request.
     * Off by default. Each adapter maps this to its own transport: a persisted
     * cURL handle, a kept-alive Swoole client, and so on.
     */
    public function withConnectionReuse(bool $enabled = true): static;

    /**
     * Follow HTTP Location redirects until the final non-redirect response.
     * Off by default, so a 3xx response is returned with its Location header.
     */
    public function withFollowRedirects(bool $enabled = true): static;
}
