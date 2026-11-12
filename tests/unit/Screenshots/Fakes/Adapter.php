<?php

declare(strict_types=1);

namespace Tests\Unit\Screenshots\Fakes;

use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter as AdapterInterface;
use Utopia\Client\Destinations;
use Utopia\Client\Redirect;
use Utopia\Client\Tls;

final class Adapter implements AdapterInterface
{
    public private(set) int $calls = 0;

    public private(set) ?RequestInterface $request = null;

    /**
     * @param list<ResponseInterface> $responses
     */
    public function __construct(private readonly array $responses)
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
        $this->request = $request;

        return $this->responses[$this->calls++] ?? throw new LogicException('No more queued responses.');
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $response = $this->sendRequest($request);
        $sink((string) $response->getBody());

        return $response;
    }
}
