<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for adapters that decorate another adapter. It forwards every
 * configuration helper to the inner adapter (returning a reconfigured clone) and
 * delegates sending unchanged; subclasses override sendRequest()/stream()
 * to add behaviour. Because a decorator is itself an Adapter, decorators compose.
 */
abstract class Decorator implements Adapter
{
    public function __construct(
        protected Adapter $adapter,
    ) {}

    public function withTimeout(float $seconds): static
    {
        return $this->wrap($this->adapter->withTimeout($seconds));
    }

    public function withConnectTimeout(float $seconds): static
    {
        return $this->wrap($this->adapter->withConnectTimeout($seconds));
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return $this->wrap($this->adapter->withSslVerification($enabled));
    }

    public function withCustomCA(string $path): static
    {
        return $this->wrap($this->adapter->withCustomCA($path));
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this->wrap($this->adapter->withCertificate($certPath, $keyPath, $passphrase));
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return $this->wrap($this->adapter->withMinTlsVersion($version));
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this->wrap($this->adapter->withConnectionReuse($enabled));
    }

    public function withFollowRedirects(bool $enabled = true): static
    {
        return $this->wrap($this->adapter->withFollowRedirects($enabled));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->adapter->sendRequest($request);
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->adapter->stream($request, $sink);
    }

    protected function wrap(Adapter $adapter): static
    {
        $clone = clone $this;
        $clone->adapter = $adapter;

        return $clone;
    }
}
