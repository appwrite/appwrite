<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Decorator;

use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Utopia\Client\Adapter;
use Utopia\Client\Destinations;
use Utopia\Client\Psr18\ResponseStreamingClientInterface;
use Utopia\Client\Redirect;
use Utopia\Client\Tls;

final class QueueAdapter implements Adapter, ResponseStreamingClientInterface
{
    public int $calls = 0;

    /**
     * @param array<int, Throwable|callable(callable(string): void, (callable(ResponseInterface): void)|null): ResponseInterface> $outcomes
     */
    public function __construct(
        private array $outcomes,
        private readonly bool $responseStreaming = false,
    ) {
    }

    #[\Override]
    public function withDestinations(Destinations $destinations): static
    {
        return $this;
    }

    #[\Override]
    public function withTimeout(float $seconds): static
    {
        return $this;
    }

    #[\Override]
    public function withConnectTimeout(float $seconds): static
    {
        return $this;
    }

    #[\Override]
    public function withSslVerification(bool $enabled = true): static
    {
        return $this;
    }

    #[\Override]
    public function withCustomCA(string $path): static
    {
        return $this;
    }

    #[\Override]
    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this;
    }

    #[\Override]
    public function withMinTlsVersion(Tls $version): static
    {
        return $this;
    }

    #[\Override]
    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this;
    }

    #[\Override]
    public function withFollowRedirects(bool $enabled = true, int $maxHops = Redirect::MAX_HOPS): static
    {
        return $this;
    }

    #[\Override]
    public function supportsResponseStreaming(): bool
    {
        return $this->responseStreaming;
    }

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->next(static function (string $chunk): void {
        }, null);
    }

    #[\Override]
    public function stream(RequestInterface $request, callable $sink, ?callable $onResponse = null): ResponseInterface
    {
        return $this->next($sink, $this->responseStreaming ? $onResponse : null);
    }

    /**
     * @param callable(string): void $sink
     * @param (callable(ResponseInterface): void)|null $onResponse
     */
    private function next(callable $sink, ?callable $onResponse): ResponseInterface
    {
        $outcome = $this->outcomes[$this->calls] ?? throw new LogicException('No more queued outcomes.');
        $this->calls++;

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome($sink, $onResponse);
    }
}
