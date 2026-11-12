<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Decorator;

use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Destinations;
use Utopia\Client\Redirect;
use Utopia\Client\Tls;

final class QueueAdapter implements Adapter
{
    public int $calls = 0;

    /**
     * @param array<int, callable(callable(string): void): ResponseInterface> $outcomes
     */
    public function __construct(private array $outcomes)
    {
    }

    public function withDestinations(Destinations $destinations): static
    {
        return $this;
    }

    public function withTimeout(float $seconds): static
    {
        return $this;
    }

    public function withConnectTimeout(float $seconds): static
    {
        return $this;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return $this;
    }

    public function withCustomCA(string $path): static
    {
        return $this;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return $this;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this;
    }

    public function withFollowRedirects(bool $enabled = true, int $maxHops = Redirect::MAX_HOPS): static
    {
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->next(static function (string $chunk): void {
        });
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->next($sink);
    }

    /**
     * @param callable(string): void $sink
     */
    private function next(callable $sink): ResponseInterface
    {
        $outcome = $this->outcomes[$this->calls] ?? throw new LogicException('No more queued outcomes.');
        $this->calls++;

        return $outcome($sink);
    }
}
