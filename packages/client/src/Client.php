<?php

declare(strict_types=1);

namespace Utopia;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Redirect;
use Utopia\Client\Tls;
use Utopia\Psr7\Header;
use Utopia\Psr7\Uri;
use Utopia\Span\Span;

final class Client implements Adapter
{
    /**
     * @var array<string, array{name: string, values: array<int, string>}>
     */
    private array $headers = [];

    private ?UriInterface $baseUri = null;

    private bool $tracePropagation = false;

    public function __construct(
        private Adapter $adapter,
    ) {}

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withTimeout($seconds);

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withConnectTimeout($seconds);

        return $clone;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withSslVerification($enabled);

        return $clone;
    }

    public function withCustomCA(string $path): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withCustomCA($path);

        return $clone;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withCertificate($certPath, $keyPath, $passphrase);

        return $clone;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withMinTlsVersion($version);

        return $clone;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withConnectionReuse($enabled);

        return $clone;
    }

    public function withFollowRedirects(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->adapter = $this->adapter->withFollowRedirects($enabled);

        return $clone;
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function withHeaders(array $headers): static
    {
        $clone = clone $this;

        foreach ($headers as $name => $values) {
            $clone->headers[strtolower($name)] = [
                'name' => $name,
                'values' => \is_array($values) ? array_values($values) : [$values],
            ];
        }

        return $clone;
    }

    public function withBaseUri(UriInterface|string $uri): static
    {
        $uri = $uri instanceof UriInterface ? $uri : Uri::parse($uri);

        if ($uri->getScheme() === '' || $uri->getHost() === '') {
            throw new InvalidArgumentException('Base URI must be absolute.');
        }

        $clone = clone $this;
        $clone->baseUri = $uri;

        return $clone;
    }

    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withHeaders([
            Header::AUTHORIZATION => 'Basic ' . base64_encode($username . ':' . $password),
        ]);
    }

    public function withBearerAuth(string $token): static
    {
        return $this->withHeaders([
            Header::AUTHORIZATION => 'Bearer ' . $token,
        ]);
    }

    /**
     * Propagate the active trace downstream as a W3C Trace Context traceparent
     * header. Off by default; requires an active utopia-php/span span.
     */
    public function withTracePropagation(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->tracePropagation = $enabled;

        return $clone;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->adapter->sendRequest($this->prepare($request));
    }

    /**
     * Send a request and pass each response body chunk to $sink as it arrives.
     * The returned response carries the status and headers; its body is empty.
     *
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->adapter->stream($this->prepare($request), $sink);
    }

    private function prepare(RequestInterface $request): RequestInterface
    {
        return $this->applyTrace(
            $this->applyHeaders(
                $this->applyBaseUri($request),
            ),
        );
    }

    private function applyBaseUri(RequestInterface $request): RequestInterface
    {
        if (!$this->baseUri instanceof \Psr\Http\Message\UriInterface) {
            return $request;
        }

        $uri = $request->getUri();

        if ($uri->getScheme() !== '' || $uri->getHost() !== '') {
            return $request;
        }

        return $request->withUri(
            $this->baseUri
                ->withPath($this->resolvePath($this->baseUri->getPath(), $uri->getPath()))
                ->withQuery($uri->getQuery())
                ->withFragment($uri->getFragment()),
        );
    }

    private function applyHeaders(RequestInterface $request): RequestInterface
    {
        foreach ($this->headers as $header) {
            if (!$request->hasHeader($header['name'])) {
                $request = $request->withHeader($header['name'], $header['values']);
            }
        }

        return $request;
    }

    /**
     * Propagate the active trace downstream as a W3C Trace Context traceparent
     * header. Skipped when disabled, no span is active, or the request already
     * carries one, so an inbound trace is never overwritten.
     */
    private function applyTrace(RequestInterface $request): RequestInterface
    {
        if (!$this->tracePropagation) {
            return $request;
        }

        $traceparent = Span::traceparent();

        if ($traceparent === null || $request->hasHeader(Header::TRACEPARENT)) {
            return $request;
        }

        return $request->withHeader(Header::TRACEPARENT, $traceparent);
    }

    private function resolvePath(string $basePath, string $path): string
    {
        if ($path === '') {
            return $basePath === '' ? '/' : $basePath;
        }

        if (str_starts_with($path, '/')) {
            return Redirect::removeDotSegments($path);
        }

        if ($basePath === '' || !str_ends_with($basePath, '/')) {
            $basePath .= '/';
        }

        return Redirect::removeDotSegments($basePath . $path);
    }
}
