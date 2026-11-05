<?php

namespace Utopia\Http\Adapter\Swoole;

use Swoole\Http\Request as SwooleRequest;
use Utopia\Http\Request as UtopiaRequest;
use Utopia\Http\TrustedHeaders;

class Request extends UtopiaRequest
{
    private const string UPLOAD_MARKER = 'swoole-upload-file';

    /**
     * Swoole Request Object
     */
    protected SwooleRequest $swoole;

    /**
     * Request constructor.
     */
    public function __construct(SwooleRequest $request, TrustedHeaders $trusted = new TrustedHeaders())
    {
        $this->swoole = $request;
        $this->trusted = $trusted;

        $this->dropSpoofedUploads();
    }

    /**
     * Get raw payload
     *
     * Method for getting the HTTP request payload as a raw string.
     */
    public function getRawPayload(): string
    {
        return $this->swoole->rawContent() ?: '';
    }

    /**
     * Get server
     *
     * Method for querying server parameters. If $key is not found $default value will be returned.
     */
    public function getServer(string $key, ?string $default = null): ?string
    {
        return $this->swoole->server[$key] ?? $default;
    }

    /**
     * Set server
     *
     * Method for setting server parameters.
     */
    public function setServer(string $key, string $value): static
    {
        $this->swoole->server[$key] = $value;

        return $this;
    }

    /**
     * Get IP
     *
     * Returns users IP address.
     * Support HTTP_X_FORWARDED_FOR header usually return
     *  from different proxy servers or PHP default REMOTE_ADDR
     */
    public function getIP(): string
    {
        $remoteAddr = $this->getServer('remote_addr') ?? '0.0.0.0';

        foreach ($this->trusted->ip as $header) {
            $headerValue = $this->getHeaderLine($header);

            if (empty($headerValue)) {
                continue;
            }

            // Leftmost IP address is the address of the originating client
            $ips = explode(',', $headerValue);
            $ip = trim($ips[0]);

            // Validate IP format (supports both IPv4 and IPv6)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $remoteAddr;
    }

    /**
     * Get Protocol
     *
     * Returns request protocol.
     * Support HTTP_X_FORWARDED_PROTO header usually return
     *  from different proxy servers or PHP default REQUEST_SCHEME
     */
    public function getProtocol(): string
    {
        $trusted = $this->trustedProtocol();

        if ($trusted !== null) {
            return $trusted;
        }

        // Nothing in front of this server stated the scheme, so fall back to
        // the request line. `server_protocol` is the HTTP version rather than a
        // scheme, so it can only say that TLS was not terminated here.
        $protocol = $this->getServer('server_protocol') ?? 'https';

        if ($protocol === 'HTTP/1.1') {
            return 'http';
        }

        return match ($protocol) {
            'http', 'https', 'ws', 'wss' => $protocol,
            default => 'https',
        };
    }

    /**
     * Get Port
     *
     * Returns request port.
     */
    public function getPort(): string
    {
        $port = $this->trustedValue($this->trusted->port);

        if ($port !== null && ctype_digit($port)) {
            return $port;
        }

        return (string) parse_url($this->getProtocol() . '://' . $this->getHost(), PHP_URL_PORT);
    }

    /**
     * Get Hostname
     *
     * Returns request hostname.
     */
    public function getHostname(): string
    {
        $hostname = parse_url($this->getProtocol() . '://' . $this->getHost(), PHP_URL_HOST);

        return strtolower(\strval($hostname));
    }

    /**
     * The host the client asked for: a trusted forwarded header when one is set, the Host header otherwise.
     */
    private function getHost(): string
    {
        return $this->trustedValue($this->trusted->host) ?? $this->getHeaderLine('host');
    }

    /**
     * Get Method
     *
     * Return HTTP request method
     */
    public function getMethod(): string
    {
        return $this->getServer('request_method') ?? 'UNKNOWN';
    }

    /**
     * Set method
     *
     * Set HTTP request method
     */
    public function setMethod(string $method): static
    {
        $this->setServer('request_method', $method);

        return $this;
    }

    /**
     * Get URI
     *
     * Return HTTP request URI
     */
    #[\Override]
    public function getURI(): string
    {
        return $this->getServer('request_uri') ?? '';
    }

    /**
     * Set URI
     *
     * Set HTTP request URI
     */
    public function setURI(string $uri): static
    {
        $this->setServer('request_uri', $uri);

        return $this;
    }

    /**
     * Get Referer
     *
     * Return HTTP referer header
     */
    public function getReferer(string $default = ''): string
    {
        return $this->getHeaderLine('referer', $default);
    }

    /**
     * Get Origin
     *
     * Return HTTP origin header
     */
    public function getOrigin(string $default = ''): string
    {
        return $this->getHeaderLine('origin', $default);
    }

    /**
     * Get User Agent
     *
     * Return HTTP user agent header
     */
    public function getUserAgent(string $default = ''): string
    {
        return $this->getHeaderLine('user-agent', $default);
    }

    /**
     * Get Accept
     *
     * Return HTTP accept header
     */
    public function getAccept(string $default = ''): string
    {
        return $this->getHeaderLine('accept', $default);
    }

    /**
     * Get files
     *
     * Method for querying upload files data. If $key is not found empty array will be returned.
     *
     * @param  string  $key
     * @return array<string, mixed>
     */
    public function getFiles($key): array
    {
        $key = strtolower($key);

        return $this->swoole->files[$key] ?? [];
    }

    /**
     * Generate cookies
     *
     * Parse request cookies into an associative array of cookie name to value.
     *
     * @return array<string, string>
     */
    protected function generateCookies(): array
    {
        if (null === $this->cookies) {
            $this->cookies = $this->swoole->cookie ?? [];
        }

        return $this->cookies;
    }

    public function getSwooleRequest(): SwooleRequest
    {
        return $this->swoole;
    }

    /**
     * Generate input
     *
     * Generate PHP input stream and parse it as an array in order to handle different content type of requests
     *
     * @return array<string, mixed>
     */
    protected function generateInput(): array
    {
        if (null === $this->queryString) {
            $this->queryString = $this->swoole->get ?? [];
        }
        if (null === $this->payload) {
            $contentType = $this->getHeaderLine('content-type');

            // Get content-type without the charset
            $length = strpos($contentType, ';');
            $length = (empty($length)) ? \strlen($contentType) : $length;
            $contentType = substr($contentType, 0, $length);

            $this->payload = match ($contentType) {
                'application/json' => $this->decodePayload(\strval($this->swoole->rawContent())),
                default => $this->swoole->post,
            };

            if (empty($this->payload)) { // Make sure we return same data type even if json payload is empty or failed
                $this->payload = [];
            }
        }

        return match ($this->getMethod()) {
            self::METHOD_POST,
            self::METHOD_PUT,
            self::METHOD_PATCH,
            self::METHOD_DELETE => $this->payload,
            default => $this->queryString,
        };
    }

    /**
     * Generate headers
     *
     * Parse Swoole request headers into a PSR-7 style map of lowercased header name
     * to a list of string values for easy querying using the getHeader method.
     *
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function generateHeaders(): array
    {
        if (null === $this->headers) {
            $headers = [];

            foreach ($this->swoole->header ?? [] as $name => $value) {
                $headers[strtolower($name)] = \is_array($value)
                    ? array_values(array_map(strval(...), $value))
                    : [(string) $value];
            }

            $this->headers = $headers;
        }

        return $this->headers;
    }

    /**
     * Drop upload paths named by the client
     *
     * Swoole's multipart parser accepts a client-supplied `Swoole-Upload-File`
     * part header as a ready temp file (swoole/swoole-src#6309): the path is
     * reported as `tmp_name`, passes `is_uploaded_file()`, and is unlinked when
     * the request is freed. Such paths leave `tmpfiles` and are blanked in `files`.
     *
     * Temporary: remove this guard and the uploadMarkers() scan once a released
     * Swoole ships the upstream fix (swoole/swoole-src#6309) and we require it.
     */
    private function dropSpoofedUploads(): void
    {
        $tmpfiles = $this->swoole->tmpfiles;

        if (! \is_array($tmpfiles) || $tmpfiles === []) {
            return;
        }

        $markers = $this->uploadMarkers();
        $spoofed = \array_values(\array_filter($tmpfiles, fn (mixed $path): bool => \is_string($path) && \in_array(\trim($path), $markers, true)));

        if ($spoofed === []) {
            return;
        }

        $this->swoole->tmpfiles = \array_values(\array_filter($tmpfiles, fn (mixed $path): bool => ! \in_array($path, $spoofed, true)));

        $files = $this->swoole->files;

        if (! \is_array($files)) {
            return;
        }

        foreach ($files as $key => $entry) {
            if (! \is_array($entry) || ! isset($entry['tmp_name'])) {
                continue;
            }

            [$entry['tmp_name'], $entry['error'], $entry['size']] = $this->dropPaths($entry['tmp_name'], $entry['error'] ?? null, $entry['size'] ?? null, $spoofed);
            $files[$key] = $entry;
        }

        $this->swoole->files = $files;
    }

    /**
     * Walk `tmp_name` with its parallel `error` and `size` trees, which nest
     * for bracketed field names such as `file[]` or `file[0][part]`.
     *
     * @param  list<string>  $spoofed
     * @return array{mixed, mixed, mixed}
     */
    private function dropPaths(mixed $path, mixed $error, mixed $size, array $spoofed): array
    {
        if (\is_string($path)) {
            if (\in_array($path, $spoofed, true)) {
                return ['', \UPLOAD_ERR_CANT_WRITE, 0];
            }

            return [$path, $error, $size];
        }

        if (! \is_array($path)) {
            return [$path, $error, $size];
        }

        foreach ($path as $position => $child) {
            [$path[$position], $childError, $childSize] = $this->dropPaths(
                $child,
                \is_array($error) ? $error[$position] ?? null : null,
                \is_array($size) ? $size[$position] ?? null : null,
                $spoofed,
            );

            if (\is_array($error)) {
                $error[$position] = $childError;
            }

            if (\is_array($size)) {
                $size[$position] = $childSize;
            }
        }

        return [$path, $error, $size];
    }

    /**
     * Paths named by `Swoole-Upload-File` part headers, which Swoole matches
     * case-insensitively at the start of a header line.
     *
     * @return list<string>
     */
    private function uploadMarkers(): array
    {
        $body = $this->swoole->rawContent();

        if (! \is_string($body)) {
            return [];
        }

        $markers = [];
        $offset = 0;

        while (($position = \stripos($body, self::UPLOAD_MARKER, $offset)) !== false) {
            $offset = $position + \strlen(self::UPLOAD_MARKER);

            if ($position === 0 || $body[$position - 1] !== "\n") {
                continue;
            }

            $end = \strpos($body, "\n", $offset);
            $line = \ltrim(\substr($body, $offset, $end === false ? null : $end - $offset), " \t");

            if (\str_starts_with($line, ':')) {
                $markers[] = \trim(\substr($line, 1));
            }
        }

        return $markers;
    }
}
