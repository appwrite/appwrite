<?php

declare(strict_types=1);

namespace Utopia\Client\Adapter\SwooleCoroutine;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as SwooleClient;
use Throwable;
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
use Utopia\Psr7\Request\Multipart\Body;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use ValueError;

class Client implements Adapter
{
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;

    private const float DEFAULT_TIMEOUT = 30.0;

    private const string SETTING_CONNECT_TIMEOUT = 'connect_timeout';

    private const string SETTING_HTTP2 = 'http2';

    private const string SETTING_KEEP_ALIVE = 'keep_alive';

    private const string SETTING_TIMEOUT = 'timeout';

    private const string SETTING_SSL_VERIFY_PEER = 'ssl_verify_peer';

    private const string SETTING_SSL_HOST_NAME = 'ssl_host_name';

    private const string SETTING_SSL_CAFILE = 'ssl_cafile';

    private const string SETTING_SSL_CERT_FILE = 'ssl_cert_file';

    private const string SETTING_SSL_KEY_FILE = 'ssl_key_file';

    private const string SETTING_SSL_PASSPHRASE = 'ssl_passphrase';

    private const string SETTING_SSL_PROTOCOLS = 'ssl_protocols';

    private readonly ResponseBuilder $responseBuilder;

    private bool $reuseConnections = false;

    private bool $followRedirects = false;

    /**
     * Buffered requests reuse one client and streamed requests another: a
     * write callback, once set on a Swoole client, stays with it, and setting
     * it to null does not take it off. A client that has streamed would hand
     * every later response body to the stale sink and buffer nothing.
     */
    private ?SwooleClient $connection = null;

    private string $connectionKey = '';

    private ?SwooleClient $streamConnection = null;

    private string $streamConnectionKey = '';

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        ResponseFactoryInterface $responseFactory = new Response\Factory(),
        StreamFactoryInterface $streamFactory = new Stream\Factory(),
        private array $settings = [],
    ) {
        $this->settings += [
            self::SETTING_CONNECT_TIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
            self::SETTING_TIMEOUT => self::DEFAULT_TIMEOUT,
            self::SETTING_SSL_VERIFY_PEER => true,
        ];

        $this->responseBuilder = new ResponseBuilder($responseFactory, $streamFactory);
    }

    public function __clone(): void
    {
        // Clones get their own connections; Swoole closes the dropped ones on GC.
        $this->forgetConnection();
    }

    public function withTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_TIMEOUT] = $this->seconds($seconds);

        return $clone;
    }

    public function withConnectTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_CONNECT_TIMEOUT] = $this->seconds($seconds);

        return $clone;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_SSL_VERIFY_PEER] = $enabled;

        return $clone;
    }

    public function withCustomCA(string $path): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_SSL_CAFILE] = $path;

        return $clone;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_SSL_CERT_FILE] = $certPath;
        $clone->settings[self::SETTING_SSL_KEY_FILE] = $keyPath;

        if ($passphrase !== null) {
            $clone->settings[self::SETTING_SSL_PASSPHRASE] = $passphrase;
        }

        return $clone;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        $clone = clone $this;
        $clone->settings[self::SETTING_SSL_PROTOCOLS] = $this->sslProtocols(match ($version) {
            Tls::V1_0 => ['SWOOLE_SSL_TLSv1', 'SWOOLE_SSL_TLSv1_1', 'SWOOLE_SSL_TLSv1_2', 'SWOOLE_SSL_TLSv1_3'],
            Tls::V1_1 => ['SWOOLE_SSL_TLSv1_1', 'SWOOLE_SSL_TLSv1_2', 'SWOOLE_SSL_TLSv1_3'],
            Tls::V1_2 => ['SWOOLE_SSL_TLSv1_2', 'SWOOLE_SSL_TLSv1_3'],
            Tls::V1_3 => ['SWOOLE_SSL_TLSv1_3'],
        });

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
        return $this->perform($request, null);
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->perform($request, $sink);
    }

    /**
     * Execute the request, following Location redirects when enabled. The
     * coroutine HTTP client has no native follow-redirects setting, so hops are
     * issued here. When streaming, intermediate redirect bodies are discarded
     * and only the final response body is forwarded to the sink.
     *
     * @param (callable(string): void)|null $sink
     *
     * @throws ClientExceptionInterface
     */
    private function perform(RequestInterface $request, ?callable $sink): ResponseInterface
    {
        if (!\extension_loaded('swoole')) {
            throw new AdapterPreconditionException($request, 'The swoole extension is required.');
        }

        if (Coroutine::getCid() < 0) {
            throw new AdapterPreconditionException($request, 'Swoole coroutine HTTP requests must run inside a coroutine.');
        }

        $this->validateSettings();

        try {
            if (!$this->followRedirects) {
                return $this->exchange($request, $sink);
            }

            $current = $request;

            for ($redirects = 0; $redirects <= Redirect::MAX_HOPS; $redirects++) {
                $response = $this->exchange($current, $sink, suppressRedirectBody: true);

                if (!Redirect::isRedirect($response)) {
                    return $response;
                }

                if ($redirects === Redirect::MAX_HOPS) {
                    throw new ProtocolException($request, 'Too many redirects.');
                }

                $current = $this->followTo($current, $response);
            }

            throw new ProtocolException($request, 'Too many redirects.');
        } finally {
            if (!$this->reuseConnections) {
                $this->forgetConnection();
            } elseif ($sink !== null) {
                $this->releaseSink();
            }
        }
    }

    /**
     * A write callback cannot be taken off a Swoole client, so replace the one
     * holding $sink with a no-op. Whatever the sink captured is released, and
     * the next stream on this connection installs its own callback anyway.
     */
    private function releaseSink(): void
    {
        $this->streamConnection?->set(['write_func' => static function (): void {
        }]);
    }

    /**
     * Execute a single hop. When $sink is given, Swoole's write callback
     * forwards each body chunk to it as data arrives, so the body is never
     * fully held in memory and the returned response has an empty body;
     * otherwise the body is buffered onto the response.
     *
     * @param (callable(string): void)|null $sink
     *
     * @throws ClientExceptionInterface
     */
    private function exchange(RequestInterface $request, ?callable $sink, bool $suppressRedirectBody = false): ResponseInterface
    {
        $uri = $request->getUri();

        if (!\in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidUriException($request, 'Requests must use an absolute URI.');
        }

        // Swoole decodes a buffered body itself, but its streaming write_func
        // delivers it undecoded — so a stream must ask for identity instead.
        $streaming = $sink !== null;

        $client = $this->connect($request, $streaming);

        $settings = $this->settings + [self::SETTING_HTTP2 => false];

        // Authoritative over any keep_alive passed in $settings.
        $settings[self::SETTING_KEEP_ALIVE] = $this->reuseConnections;

        if ($uri->getScheme() === 'https') {
            // SNI alone does not verify hostnames; verify against this hop's URI host.
            $settings[self::SETTING_SSL_HOST_NAME] = $uri->getHost();

            // Reject IP literals: Swoole's X509_check_host can mistake numeric DNS SANs for IP identities.
            if ($settings[self::SETTING_SSL_VERIFY_PEER] && filter_var(trim($uri->getHost(), '[]'), FILTER_VALIDATE_IP) !== false) {
                throw new TlsException($request, 'Verified HTTPS requests to IP literals are not supported by the Swoole adapter; use a DNS hostname or the cURL adapter.');
            }
        }

        $suppressedBody = null;

        if ($sink !== null) {
            if ($suppressRedirectBody) {
                $suppressedBody = tmpfile();

                if ($suppressedBody === false) {
                    throw new InvalidArgumentException('Unable to buffer a redirect response body.');
                }
            }

            $settings['write_func'] = function (SwooleClient $client, string $chunk) use ($sink, $suppressRedirectBody, $suppressedBody): void {
                if ($suppressRedirectBody && $this->nativeResponseHasLocation($client)) {
                    if (\is_resource($suppressedBody)) {
                        fwrite($suppressedBody, $chunk);
                    }

                    return;
                }

                $sink($chunk);
            };
        } else {
            // $settings passes native settings through, and a caller-supplied
            // write_func would leave every buffered body empty.
            $settings['write_func'] = null;
        }

        try {
            if ($client->set($settings) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole client settings.');
            }

            if ($client->setMethod($request->getMethod()) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole request method.');
            }

            $body = $request->getBody();
            $multipart = $body instanceof Body && $this->streamableMultipart($body) ? $body : null;

            $headers = $this->requestHeaders($request);

            if ($streaming && !$request->hasHeader(Header::ACCEPT_ENCODING)) {
                $headers[Header::ACCEPT_ENCODING] = 'identity';
            }

            // Swoole generates its own multipart Content-Type (with its own
            // boundary) when files are attached, so drop ours to avoid a clash.
            if ($multipart instanceof \Utopia\Psr7\Request\Multipart\Body) {
                $headers = $this->withoutContentType($headers);
            }

            if ($client->setHeaders($headers) === false) {
                throw new InvalidArgumentException('Unable to configure Swoole request headers.');
            }

            // Swoole never clears requestBody and only clears uploadFiles after a
            // successful response, so clear whichever this request omits.
            if ($multipart instanceof \Utopia\Psr7\Request\Multipart\Body) {
                $client->requestBody = null;
                $this->attachMultipart($client, $multipart);
            } else {
                $client->uploadFiles = null;
                $data = (string) $body;

                if ($data === '') {
                    $client->requestBody = null;
                } elseif ($client->setData($data) === false) {
                    throw new InvalidArgumentException('Unable to configure Swoole request body.');
                }
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw $invalidArgumentException;
        } catch (Throwable $throwable) {
            throw new InvalidArgumentException($throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }

        try {
            $result = $client->execute($this->path($request));
        } catch (Throwable $throwable) {
            throw $this->networkException($request, $throwable->getMessage(), (int) $throwable->getCode(), null, $throwable);
        }

        if ($result === false) {
            $message = \is_string($client->errMsg) && $client->errMsg !== '' ? $client->errMsg : 'Swoole request failed.';
            $code = \is_int($client->errCode) ? $client->errCode : 0;
            $statusCode = $client->statusCode;

            if ($this->isTimeout($message, $code, $statusCode)) {
                throw new TimeoutException($request, $message, $code);
            }

            throw $this->networkException($request, $message, $code, $statusCode, null, $client->headers);
        }

        $statusCode = $client->statusCode;

        if (!\is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
            throw new InvalidResponseException($request, 'Received an invalid HTTP response.');
        }

        $headers = $client->headers;

        if (!\is_array($headers)) {
            $headers = [];
        }

        $headers = $this->headers($headers);

        if (!$streaming) {
            $headers = $this->decoded($headers);
        }

        // A streamed body already went to the sink through write_func.
        $responseBody = '';

        if (!$streaming && \is_string($client->body)) {
            $responseBody = $client->body;
        }

        // Swoole keeps or closes the socket itself; never close it here.
        $response = $this->responseBuilder->build(
            $statusCode,
            '',
            $headers,
            $responseBody,
        );

        if (\is_resource($suppressedBody) && $sink !== null && !Redirect::isRedirect($response)) {
            rewind($suppressedBody);

            while (($chunk = fread($suppressedBody, 8192)) !== false && $chunk !== '') {
                $sink($chunk);
            }
        }

        return $response;
    }

    /**
     * @throws ClientExceptionInterface
     */
    private function followTo(RequestInterface $request, ResponseInterface $response): RequestInterface
    {
        $from = $request->getUri();

        try {
            $uri = Redirect::resolve($from, $response->getHeaderLine(Header::LOCATION));
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new InvalidUriException($request, 'Invalid redirect location.', 0, $invalidArgumentException);
        }

        if (!\in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidUriException($request, 'Redirect location must be an absolute HTTP URI.');
        }

        $status = $response->getStatusCode();
        $method = strtoupper($request->getMethod());

        if (\in_array($status, [301, 302, 303], true) && !\in_array($method, ['GET', 'HEAD'], true)) {
            $request = $request
                ->withMethod(Method::GET)
                ->withBody(new Stream\Factory()->createStream(''))
                ->withoutHeader(Header::CONTENT_LENGTH)
                ->withoutHeader(Header::CONTENT_TYPE);
        } else {
            $body = $request->getBody();

            if ($body->isSeekable()) {
                $body->rewind();
            }
        }

        $request = $request->withUri($uri)->withoutHeader(Header::HOST);

        if (Redirect::shouldStripSensitiveHeaders($from, $uri)) {
            return Redirect::withoutSensitiveHeaders($request);
        }

        return $request;
    }

    private function nativeResponseHasLocation(SwooleClient $client): bool
    {
        if (!\is_array($client->headers)) {
            return false;
        }

        return array_any($client->headers, fn ($value, $name): bool => \is_string($name) && strcasecmp($name, Header::LOCATION) === 0 && \is_string($value) && $value !== '');
    }

    /**
     * Swoole binds a client to its origin at construction and re-checks the
     * socket before each request, reconnecting if it was dropped — so a cached
     * client is reused per origin and stays usable even after an error.
     *
     * The object is cached for every hop of a single perform(), including
     * when keep-alive is off, so a 50-hop follow does not leave 50 clients
     * for later GC (which can abort PHP while another client reconnects).
     *
     * @throws ClientExceptionInterface
     */
    private function connect(RequestInterface $request, bool $streaming): SwooleClient
    {
        $uri = $request->getUri();
        $secure = $uri->getScheme() === 'https';
        $key = $uri->getHost() . ':' . $this->port($request) . ':' . ($secure ? 's' : 'p');

        $connection = $streaming ? $this->streamConnection : $this->connection;
        $connectionKey = $streaming ? $this->streamConnectionKey : $this->connectionKey;

        if ($connection instanceof SwooleClient && $connectionKey === $key) {
            $socket = $connection->socket ?? null;
            if ($secure && $socket instanceof Coroutine\Socket) {
                // SSL_peek can fail with errno=0 after an abrupt TLS EOF. Swoole's
                // liveness check treats that as alive. Close before sending any body;
                // a healthy idle socket instead reports a would-block error.
                $peek = $socket->peek(1);
                if ($peek === '' || ($peek === false && $socket->errCode === 0)) {
                    $connection->close();
                }
            }

            return $connection;
        }

        try {
            $client = new SwooleClient($uri->getHost(), $this->port($request), $secure);
        } catch (Throwable $throwable) {
            throw new AdapterInitializationException($request, $throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }

        if ($streaming) {
            $this->streamConnection = $client;
            $this->streamConnectionKey = $key;
        } else {
            $this->connection = $client;
            $this->connectionKey = $key;
        }

        return $client;
    }

    private function forgetConnection(): void
    {
        $this->connection = null;
        $this->connectionKey = '';
        $this->streamConnection = null;
        $this->streamConnectionKey = '';
    }

    private function port(RequestInterface $request): int
    {
        $uri = $request->getUri();
        $port = $uri->getPort();

        if ($port !== null) {
            return $port;
        }

        return $uri->getScheme() === 'https' ? 443 : 80;
    }

    private function seconds(float $seconds): float
    {
        if ($seconds < 0.0 || !is_finite($seconds)) {
            throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
        }

        return $seconds;
    }

    private function validateSettings(): void
    {
        foreach ([self::SETTING_TIMEOUT, self::SETTING_CONNECT_TIMEOUT] as $setting) {
            if (!\array_key_exists($setting, $this->settings)) {
                continue;
            }

            $value = $this->settings[$setting];

            if (!\is_int($value) && !\is_float($value)) {
                throw new InvalidArgumentException('Swoole setting "' . $setting . '" must be a finite number greater than or equal to zero.');
            }

            if ($value < 0 || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Swoole setting "' . $setting . '" must be a finite number greater than or equal to zero.');
            }
        }

        if (\array_key_exists(self::SETTING_HTTP2, $this->settings) && !\is_bool($this->settings[self::SETTING_HTTP2])) {
            throw new InvalidArgumentException('Swoole setting "' . self::SETTING_HTTP2 . '" must be a boolean.');
        }
    }

    private function path(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        $query = $uri->getQuery();

        if ($query === '') {
            return $path;
        }

        return $path . '?' . $query;
    }

    private function isTimeout(string $message, int $code, mixed $statusCode): bool
    {
        unset($message);

        if ($this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT', -2)) {
            return true;
        }

        return \in_array($code, $this->nativeCodes([
            'SOCKET_ETIMEDOUT',
            'SWOOLE_ERROR_SOCKET_POLL_TIMEOUT',
        ], [110]), true);
    }

    private function networkException(RequestInterface $request, string $message, int $code, mixed $statusCode = null, ?Throwable $previous = null, mixed $headers = null): NetworkException
    {
        if ($this->isTimeout($message, $code, $statusCode)) {
            return new TimeoutException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_DNSLOOKUP_RESOLVE_FAILED',
            'SWOOLE_ERROR_DNSLOOKUP_RESOLVE_TIMEOUT',
            'SWOOLE_ERROR_DNSLOOKUP_NO_SERVER',
        ]), true)) {
            return new DnsException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_SSL_NOT_READY',
            'SWOOLE_ERROR_SSL_EMPTY_PEER_CERTIFICATE',
            'SWOOLE_ERROR_SSL_VERIFY_FAILED',
            'SWOOLE_ERROR_SSL_BAD_CLIENT',
            'SWOOLE_ERROR_SSL_BAD_PROTOCOL',
            'SWOOLE_ERROR_SSL_RESET',
            'SWOOLE_ERROR_SSL_HANDSHAKE_FAILED',
            'SWOOLE_ERROR_SSL_CREATE_CONTEXT_FAILED',
            'SWOOLE_ERROR_SSL_CREATE_SESSION_FAILED',
        ]), true)) {
            return new TlsException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_HTTP_PROXY_HANDSHAKE_ERROR',
            'SWOOLE_ERROR_HTTP_PROXY_HANDSHAKE_FAILED',
            'SWOOLE_ERROR_HTTP_PROXY_BAD_RESPONSE',
            'SWOOLE_ERROR_SOCKS5_UNSUPPORT_VERSION',
            'SWOOLE_ERROR_SOCKS5_UNSUPPORT_METHOD',
            'SWOOLE_ERROR_SOCKS5_AUTH_FAILED',
            'SWOOLE_ERROR_SOCKS5_SERVER_ERROR',
            'SWOOLE_ERROR_SOCKS5_HANDSHAKE_FAILED',
        ]), true)) {
            return new ProxyException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SWOOLE_ERROR_PROTOCOL_ERROR',
            'SWOOLE_ERROR_HTTP_INVALID_PROTOCOL',
            'SWOOLE_ERROR_PACKAGE_MALFORMED_DATA',
            'SWOOLE_ERROR_HTTP2_STREAM_NO_HEADER',
            'SWOOLE_ERROR_HTTP2_SEND_CONTROL_FRAME_FAILED',
            'SWOOLE_ERROR_HTTP2_INTERNAL_ERROR',
        ]), true)) {
            return new ProtocolException($request, $message, $code, $previous);
        }

        if (\is_array($headers) && \in_array($code, $this->nativeCodes([
            'SOCKET_ECONNRESET',
        ], [104]), true)) {
            return new ProtocolException($request, $message, $code, $previous);
        }

        if (\in_array($code, $this->nativeCodes([
            'SOCKET_EPIPE',
            'SOCKET_ENETUNREACH',
            'SOCKET_ECONNRESET',
            'SOCKET_ECONNREFUSED',
            'SOCKET_EHOSTUNREACH',
            'SWOOLE_ERROR_CLIENT_NO_CONNECTION',
            'SWOOLE_ERROR_SESSION_CLOSED_BY_SERVER',
            'SWOOLE_ERROR_SESSION_CLOSED_BY_CLIENT',
            'SWOOLE_ERROR_SESSION_CLOSED',
        ], [32, 101, 104, 111, 113]), true)) {
            return new ConnectionException($request, $message, $code, $previous);
        }

        if ($this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED', -1) || $this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET', -3) || $this->statusCodeIs($statusCode, 'SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED', -4)) {
            return new ConnectionException($request, $message, $code, $previous);
        }

        return new NetworkException($request, $message, $code, $previous);
    }

    /**
     * OR the given SWOOLE_SSL_* protocol constants into the bitmask Swoole's
     * ssl_protocols setting expects, skipping any the runtime does not define.
     *
     * @param array<int, string> $names
     */
    private function sslProtocols(array $names): int
    {
        $mask = 0;

        foreach ($names as $name) {
            if (!\defined($name)) {
                continue;
            }

            $value = \constant($name);

            if (\is_int($value)) {
                $mask |= $value;
            }
        }

        return $mask;
    }

    /**
     * @param array<int, string> $names
     * @param array<int, int> $fallbacks
     *
     * @return array<int, int>
     */
    private function nativeCodes(array $names, array $fallbacks = []): array
    {
        $codes = $fallbacks;

        foreach ($names as $name) {
            if (!\defined($name)) {
                continue;
            }

            $code = \constant($name);

            if (\is_int($code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function statusCodeIs(mixed $statusCode, string $constant, int $fallback): bool
    {
        if (!\is_int($statusCode)) {
            return false;
        }

        if (\defined($constant) && \constant($constant) === $statusCode) {
            return true;
        }

        return $statusCode === $fallback;
    }

    /**
     * Whether a multipart body can be sent through Swoole's native upload API,
     * which streams files from disk with sendfile(). It only models simple
     * uploads: at least one file or data part, no custom per-part headers, no
     * repeated field names, and no empty files (Swoole rejects those). Anything
     * else falls back to buffering the serialised body.
     */
    private function streamableMultipart(Body $body): bool
    {
        $hasUpload = false;
        $fields = [];

        foreach ($body->parts() as $part) {
            if ($part->headers() !== []) {
                return false;
            }

            if ($part->path() !== null) {
                if ($part->size() === 0) {
                    return false;
                }

                $hasUpload = true;

                continue;
            }

            if ($part->filename() !== null) {
                $hasUpload = true;

                continue;
            }

            if (isset($fields[$part->name()])) {
                return false;
            }

            $fields[$part->name()] = true;
        }

        return $hasUpload;
    }

    private function attachMultipart(SwooleClient $client, Body $body): void
    {
        $fields = [];

        foreach ($body->parts() as $part) {
            if ($part->path() !== null) {
                if ($client->addFile($part->path(), $part->name(), $part->contentType() ?? '', $part->filename() ?? '') === false) {
                    throw new InvalidArgumentException('Unable to attach Swoole multipart file.');
                }

                continue;
            }

            if ($part->filename() !== null) {
                if ($client->addData($part->content(), $part->name(), $part->contentType() ?? '', $part->filename()) === false) {
                    throw new InvalidArgumentException('Unable to attach Swoole multipart data.');
                }

                continue;
            }

            $fields[$part->name()] = $part->content();
        }

        if ($fields !== [] && $client->setData($fields) === false) {
            throw new InvalidArgumentException('Unable to configure Swoole multipart fields.');
        }
    }

    /**
     * @param array<array-key, string> $headers
     *
     * @return array<array-key, string>
     */
    private function withoutContentType(array $headers): array
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'content-type') {
                unset($headers[$name]);
            }
        }

        return $headers;
    }

    /**
     * @return array<array-key, string>
     */
    private function requestHeaders(RequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        if (!$request->hasHeader('Host') && $request->getUri()->getHost() !== '') {
            $headers['Host'] = $this->host($request);
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
     * Swoole decodes a buffered gzip/deflate/br body but leaves the original
     * Content-Encoding and Content-Length, which now misdescribe the plaintext
     * body, so drop both for an encoding Swoole decodes.
     *
     * @param array<string, array<int, string>> $headers
     *
     * @return array<string, array<int, string>>
     */
    private function decoded(array $headers): array
    {
        $decoded = false;

        foreach ($headers as $name => $values) {
            if (strcasecmp($name, Header::CONTENT_ENCODING) !== 0) {
                continue;
            }

            foreach ($values as $value) {
                if (\in_array(strtolower(trim($value)), ['gzip', 'deflate', 'br'], true)) {
                    $decoded = true;
                }
            }
        }

        if (!$decoded) {
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
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, array<int, string>>
     */
    private function headers(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }

            if (\is_string($value)) {
                $normalized[$name] = [$value];

                continue;
            }

            if (!\is_array($value)) {
                continue;
            }

            foreach ($value as $singleValue) {
                if (\is_string($singleValue)) {
                    $normalized[$name][] = $singleValue;
                }
            }
        }

        return $normalized;
    }
}
