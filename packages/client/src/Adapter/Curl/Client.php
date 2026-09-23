<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\Curl;

use CurlHandle;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Utopia\Client\Adapter;
use Utopia\Client\Exception\AdapterInitializationException;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\InvalidResponseException;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\ProtocolException;
use Utopia\Client\Exception\ProxyException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Redirect;
use Utopia\Client\Response\Builder as ResponseBuilder;
use Utopia\Client\Tls;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use ValueError;

class Client implements Adapter
{
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;

    private const float DEFAULT_TIMEOUT = 30.0;

    private readonly ResponseBuilder $responseBuilder;

    private bool $reuseConnections = false;

    private bool $followRedirects = false;

    private ?CurlHandle $handle = null;

    /**
     * Native cURL options. Values override adapter defaults when keys overlap.
     *
     * @param array<int, mixed> $options
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory = new Response\Factory(),
        StreamFactoryInterface $streamFactory = new Stream\Factory(),
        private array $options = [],
    ) {
        $this->options += [
            \CURLOPT_CONNECTTIMEOUT_MS => $this->milliseconds(self::DEFAULT_CONNECT_TIMEOUT),
            \CURLOPT_TIMEOUT_MS => $this->milliseconds(self::DEFAULT_TIMEOUT),
        ];

        $this->responseBuilder = new ResponseBuilder($responseFactory, $streamFactory);
    }

    public function __clone(): void
    {
        // Clones get their own handle and connection cache.
        $this->handle = null;
    }

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_TIMEOUT_MS] = $this->milliseconds($seconds);

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_CONNECTTIMEOUT_MS] = $this->milliseconds($seconds);

        return $clone;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_SSL_VERIFYPEER] = $enabled;
        $clone->options[\CURLOPT_SSL_VERIFYHOST] = $enabled ? 2 : 0;

        return $clone;
    }

    public function withCustomCA(string $path): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_CAINFO] = $path;

        return $clone;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_SSLCERT] = $certPath;
        $clone->options[\CURLOPT_SSLKEY] = $keyPath;

        if ($passphrase !== null) {
            $clone->options[\CURLOPT_KEYPASSWD] = $passphrase;
        }

        return $clone;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        $clone = clone $this;
        $clone->options[\CURLOPT_SSLVERSION] = match ($version) {
            Tls::V1_0 => \CURL_SSLVERSION_TLSv1_0,
            Tls::V1_1 => \CURL_SSLVERSION_TLSv1_1,
            Tls::V1_2 => \CURL_SSLVERSION_TLSv1_2,
            Tls::V1_3 => \CURL_SSLVERSION_TLSv1_3,
        };

        return $clone;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->reuseConnections = $enabled;

        return $clone;
    }

    public function withFollowRedirects(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->followRedirects = $enabled;

        return $clone;
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = '';

        $parsed = $this->transfer($request, static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        return $this->responseBuilder->build(
            $parsed['status'],
            $parsed['reason'],
            $parsed['headers'],
            $body,
            $parsed['protocol'],
        );
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $parsed = $this->transfer($request, $sink);

        return $this->responseBuilder->build(
            $parsed['status'],
            $parsed['reason'],
            $parsed['headers'],
            '',
            $parsed['protocol'],
        );
    }

    /**
     * Run the transfer, forwarding each body chunk to $sink, and return the
     * parsed status line and headers. cURL invokes the write callback as data
     * arrives, so a streaming $sink sees the body chunk-by-chunk and the body
     * is never fully held in memory.
     *
     * @param callable(string): void $sink
     *
     * @return array{protocol: string, status: int, reason: string, headers: array<string, array<int, string>>}
     *
     * @throws ClientExceptionInterface
     */
    private function transfer(RequestInterface $request, callable $sink): array
    {
        if (!\extension_loaded('curl')) {
            throw new AdapterPreconditionException($request, 'The curl extension is required.');
        }

        $uri = $request->getUri();

        if (!\in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidUriException($request, 'Requests must use an absolute URI.');
        }

        // Skipped when the caller negotiates encoding themselves.
        $decompress = !$request->hasHeader(Header::ACCEPT_ENCODING);

        $headers = '';
        $handle = $this->handle($request);
        $options = $this->options($request, $headers, $sink, $decompress);

        try {
            if (curl_setopt_array($handle, $options) === false) {
                throw new InvalidArgumentException('Unable to configure curl.');
            }

            $result = curl_exec($handle);
        } catch (ValueError $valueError) {
            throw new InvalidArgumentException($valueError->getMessage(), 0, $valueError);
        }

        if ($result === false) {
            $message = curl_error($handle);
            $code = curl_errno($handle);

            throw $this->networkException($request, $message === '' ? 'Curl request failed.' : $message, $code);
        }

        if (!preg_match("/\r\n\r\n|\n\n|\r\r/", $headers)) {
            throw new ConnectionException($request, 'Connection closed before a complete HTTP response was received.');
        }

        $parsed = $this->parseHeaderBlock($headers);

        if ($parsed['status'] < 100 || $parsed['status'] > 599) {
            throw new InvalidResponseException($request, 'Received an invalid HTTP response.');
        }

        if ($decompress) {
            $parsed['headers'] = $this->decoded($parsed['headers']);
        }

        return $parsed;
    }

    /**
     * curl_reset() clears per-request options but keeps the handle's connection
     * cache, so a kept handle reuses the socket; a fresh handle starts anew.
     *
     * @throws ClientExceptionInterface
     */
    private function handle(RequestInterface $request): CurlHandle
    {
        if ($this->reuseConnections && $this->handle instanceof CurlHandle) {
            curl_reset($this->handle);

            return $this->handle;
        }

        $handle = curl_init();

        if (!$handle instanceof CurlHandle) {
            throw new AdapterInitializationException($request, 'Unable to initialize curl.');
        }

        if ($this->reuseConnections) {
            $this->handle = $handle;
        }

        return $handle;
    }

    /**
     * @param-out string             $headers
     * @param callable(string): void $sink
     *
     * @return array<int, mixed>
     */
    private function options(RequestInterface $request, string &$headers, callable $sink, bool $decompress): array
    {
        $options = [
            \CURLOPT_URL => (string) $request->getUri(),
            \CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            \CURLOPT_NOBODY => $request->getMethod() === Method::HEAD,
            \CURLOPT_HEADER => false,
            \CURLOPT_HTTPHEADER => $this->headers($request),
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
            \CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                unset($handle);
                $headers .= $line;

                return \strlen($line);
            },
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use ($sink): int {
                unset($handle);
                $sink($chunk);

                return \strlen($chunk);
            },
        ];

        $body = $request->getBody();
        $size = $body->getSize();

        // Stream the body through a read callback so it is never fully held in
        // memory. cURL pulls it in chunks; we hand it the size when known so the
        // request carries Content-Length, and fall back to chunked otherwise.
        if ($size !== 0) {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $options[\CURLOPT_UPLOAD] = true;
            $options[\CURLOPT_READFUNCTION] = static function (CurlHandle $handle, mixed $resource, int $length) use ($body): string {
                unset($handle, $resource);

                return $body->eof() ? '' : $body->read($length);
            };

            if ($size !== null) {
                $options[\CURLOPT_INFILESIZE] = $size;
            }
        }

        // '' advertises every codec cURL was built with and decodes the response.
        if ($decompress) {
            $options[\CURLOPT_ENCODING] = '';
        }

        $merged = $this->options + $options;

        // Authoritative over any caller-supplied CURLOPT_FORBID_REUSE /
        // CURLOPT_FOLLOWLOCATION so constructor options cannot silently override
        // the helpers.
        $merged[\CURLOPT_FORBID_REUSE] = !$this->reuseConnections;
        $merged[\CURLOPT_FOLLOWLOCATION] = $this->followRedirects;

        if ($this->followRedirects) {
            $merged[\CURLOPT_MAXREDIRS] = Redirect::MAX_HOPS;
        }

        return $merged;
    }

    private function milliseconds(float $seconds): int
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        return (int) round($seconds * 1000);
    }

    private function networkException(RequestInterface $request, string $message, int $code): NetworkException
    {
        if ($code === \CURLE_OPERATION_TIMEDOUT) {
            return new TimeoutException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_COULDNT_RESOLVE_HOST',
            'CURLE_COULDNT_RESOLVE_PROXY',
        ]), true)) {
            return new DnsException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_PROXY',
            'CURLE_HTTP_PROXYTUNNEL',
        ]), true)) {
            return new ProxyException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CACERT',
            'CURLE_SSL_PEER_CERTIFICATE',
            'CURLE_SSL_CACERT_BADFILE',
            'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER',
            'CURLE_SSL_ENGINE_NOTFOUND',
            'CURLE_SSL_ENGINE_SETFAILED',
            'CURLE_SSL_ENGINE_INITFAILED',
            'CURLE_USE_SSL_FAILED',
            'CURLE_SSL_SHUTDOWN_FAILED',
            'CURLE_SSL_CRL_BADFILE',
            'CURLE_SSL_ISSUER_ERROR',
            'CURLE_SSL_PINNEDPUBKEYNOTMATCH',
            'CURLE_SSL_INVALIDCERTSTATUS',
            'CURLE_SSL_CLIENTCERT',
            'CURLE_ECH_REQUIRED',
        ]), true)) {
            return new TlsException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_UNSUPPORTED_PROTOCOL',
            'CURLE_HTTP2',
            'CURLE_HTTP2_STREAM',
            'CURLE_HTTP3',
            'CURLE_QUIC_CONNECT_ERROR',
            'CURLE_WEIRD_SERVER_REPLY',
            'CURLE_BAD_CONTENT_ENCODING',
            'CURLE_CHUNK_FAILED',
            'CURLE_TOO_MANY_REDIRECTS',
            'CURLE_PARTIAL_FILE',
        ]), true)) {
            return new ProtocolException($request, $message, $code);
        }

        if (\in_array($code, $this->curlCodes([
            'CURLE_COULDNT_CONNECT',
            'CURLE_SEND_ERROR',
            'CURLE_RECV_ERROR',
            'CURLE_GOT_NOTHING',
            'CURLE_INTERFACE_FAILED',
            'CURLE_NO_CONNECTION_AVAILABLE',
            'CURLE_UNRECOVERABLE_POLL',
            'CURLE_AGAIN',
        ]), true)) {
            return new ConnectionException($request, $message, $code);
        }

        return new NetworkException($request, $message, $code);
    }

    /**
     * @param array<int, string> $names
     *
     * @return array<int, int>
     */
    private function curlCodes(array $names): array
    {
        $codes = [];

        foreach ($names as $name) {
            if (\defined($name)) {
                $code = \constant($name);

                if (\is_int($code)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    private function headers(RequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        if (!$request->hasHeader('Host') && $request->getUri()->getHost() !== '') {
            $headers[] = 'Host: ' . $this->host($request);
        }

        return $headers;
    }

    private function host(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        if ($port === null) {
            return $uri->getHost();
        }

        return $uri->getHost() . ':' . $port;
    }

    /**
     * cURL decodes the body but leaves the original Content-Encoding and
     * Content-Length, which now misdescribe the plaintext body, so drop both
     * when an encoding was present.
     *
     * @param array<string, array<int, string>> $headers
     *
     * @return array<string, array<int, string>>
     */
    private function decoded(array $headers): array
    {
        $encoded = false;

        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, Header::CONTENT_ENCODING) === 0) {
                $encoded = true;
            }
        }

        if (!$encoded) {
            return $headers;
        }

        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, Header::CONTENT_ENCODING) === 0 || strcasecmp($name, Header::CONTENT_LENGTH) === 0) {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * @return array{protocol: string, status: int, reason: string, headers: array<string, array<int, string>>}
     */
    private function parseHeaderBlock(string $headerBlock): array
    {
        $blocks = preg_split("/\r\n\r\n|\n\n|\r\r/", trim($headerBlock));
        $headers = $blocks === false ? [] : array_values(array_filter($blocks));
        $header = $headers === [] ? '' : array_last($headers);
        $lines = preg_split("/\r\n|\n|\r/", $header);
        $lines = $lines === false ? [] : $lines;

        $statusLine = array_shift($lines) ?? '';

        $protocol = '1.1';
        $status = 0;
        $reason = '';

        if (preg_match('/^HTTP\/(?P<protocol>\d+(?:\.\d+)?)\s+(?P<status>\d{3})(?:\s+(?P<reason>.*))?$/i', $statusLine, $matches) === 1) {
            $protocol = $matches['protocol'];
            $status = (int) $matches['status'];
            $reason = $matches['reason'] ?? '';
        }

        $parsedHeaders = [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $parsedHeaders[$name][] = trim($value);
        }

        return [
            'protocol' => $protocol,
            'status' => $status,
            'reason' => $reason,
            'headers' => $parsedHeaders,
        ];
    }
}
