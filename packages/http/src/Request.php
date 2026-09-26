<?php

namespace Utopia\Http;

abstract class Request
{
    /**
     * HTTP methods
     */
    public const string METHOD_OPTIONS = 'OPTIONS';

    public const string METHOD_GET = 'GET';

    public const string METHOD_HEAD = 'HEAD';

    public const string METHOD_POST = 'POST';

    public const string METHOD_PATCH = 'PATCH';

    public const string METHOD_PUT = 'PUT';

    public const string METHOD_DELETE = 'DELETE';

    public const string METHOD_TRACE = 'TRACE';

    public const string METHOD_CONNECT = 'CONNECT';

    /**
     * Schemes a trusted proto header may name.
     *
     * @var array<int, string>
     */
    public const array SCHEMES = ['http', 'https', 'ws', 'wss'];

    /**
     * Container for php://input parsed stream as an associative array
     *
     * @var array<string, mixed>|null
     */
    protected $payload;

    /**
     * Container for parsed query string params
     *
     * @var array<string, mixed>|null
     */
    protected $queryString;

    /**
     * Container for parsed headers
     *
     * Each header is stored under its lowercased name and maps to a list of
     * one or more string values, following the PSR-7 header representation.
     *
     * @var array<string, array<int, string>>|null
     */
    protected ?array $headers = null;

    /**
     * Container for parsed cookies
     *
     * @var array<string, string>|null
     */
    protected ?array $cookies = null;

    /**
     * Which forwarded headers this server believes. Set at construction by the
     * adapter, so a request cannot be talked into trusting a new hop later.
     */
    protected TrustedHeaders $trusted;

    /**
     * Get Param
     *
     * Get param by current method name
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        $params = $this->getParams();

        return $params[$key] ?? $default;
    }

    /**
     * Get Params
     *
     * Get all params of current method
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->generateInput();
    }

    /**
     * Get Query
     *
     * Method for querying HTTP GET request parameters. If $key is not found $default value will be returned.
     */
    public function getQuery(string $key, mixed $default = null): mixed
    {
        $this->generateInput();

        return $this->queryString[$key] ?? $default;
    }

    /**
     * Get payload
     *
     * Method for querying HTTP request payload parameters. If $key is not found $default value will be returned.
     */
    public function getPayload(string $key, mixed $default = null): mixed
    {
        $this->generateInput();

        return $this->payload[$key] ?? $default;
    }

    /**
     * Get raw payload
     *
     * Method for getting the HTTP request payload as a raw string.
     */
    abstract public function getRawPayload(): string;

    /**
     * Get server
     *
     * Method for querying server parameters. If $key is not found $default value will be returned.
     */
    abstract public function getServer(string $key, ?string $default = null): ?string;

    /**
     * Set server
     *
     * Method for setting server parameters.
     */
    abstract public function setServer(string $key, string $value): static;

    /**
     * Read the scheme from the first trusted header that carries a known one.
     */
    protected function trustedProtocol(): ?string
    {
        foreach ($this->trusted->proto as $header) {
            $value = $this->getHeaderLine($header);

            if ($value === '') {
                continue;
            }

            // Each hop appends, so the leftmost value is the client's own.
            $scheme = strtolower(trim(explode(',', $value)[0]));

            if (\in_array($scheme, self::SCHEMES, true)) {
                return $scheme;
            }
        }

        return null;
    }

    /**
     * Get IP
     *
     * Returns users IP address.
     * Support HTTP_X_FORWARDED_FOR header usually return
     *  from different proxy servers or PHP default REMOTE_ADDR
     */
    abstract public function getIP(): string;

    /**
     * Get Protocol
     *
     * Returns request protocol.
     * Support HTTP_X_FORWARDED_PROTO header usually return
     *  from different proxy servers or PHP default REQUEST_SCHEME
     */
    abstract public function getProtocol(): string;

    /**
     * Get Port
     *
     * Returns request port.
     */
    abstract public function getPort(): string;

    /**
     * Get Hostname
     *
     * Returns request hostname.
     */
    abstract public function getHostname(): string;

    /**
     * Get Method
     *
     * Return HTTP request method
     */
    abstract public function getMethod(): string;

    /**
     * Set Method
     *
     * Set HTTP request method
     */
    abstract public function setMethod(string $method): static;

    /**
     * Get URI
     *
     * Return HTTP request URI
     */
    public function getURI(): string
    {
        return $this->getServer('REQUEST_URI') ?? '';
    }

    /**
     * Get Path
     *
     * Return HTTP request path
     */
    abstract public function setURI(string $uri): static;

    /**
     * Get files
     *
     * Method for querying upload files data. If $key is not found empty array will be returned.
     *
     * @return array<string, mixed>
     */
    abstract public function getFiles(string $key): array;

    /**
     * Get Referer
     *
     * Return HTTP referer header
     */
    abstract public function getReferer(string $default = ''): string;

    /**
     * Get Origin
     *
     * Return HTTP origin header
     */
    abstract public function getOrigin(string $default = ''): string;

    /**
     * Get User Agent
     *
     * Return HTTP user agent header
     */
    abstract public function getUserAgent(string $default = ''): string;

    /**
     * Get Accept
     *
     * Return HTTP accept header
     */
    abstract public function getAccept(string $default = ''): string;

    /**
     * Get cookie
     *
     * Method for querying a single HTTP cookie. If $key is not found $default value will be returned.
     */
    public function getCookie(string $key, string $default = ''): string
    {
        $cookies = $this->generateCookies();

        return $cookies[$key] ?? $default;
    }

    /**
     * Get cookie params
     *
     * Method for getting all HTTP cookies as an associative array, following PSR-7.
     *
     * @return array<string, string>
     */
    public function getCookieParams(): array
    {
        return $this->generateCookies();
    }

    /**
     * Set cookie params
     *
     * Replace the request cookies with the given associative array, following PSR-7.
     *
     * @param  array<string, string>  $cookies
     */
    public function setCookieParams(array $cookies): static
    {
        $this->cookies = $cookies;

        return $this;
    }

    /**
     * Has header
     *
     * Checks if a header exists by the given case-insensitive name, following PSR-7.
     */
    public function hasHeader(string $key): bool
    {
        $headers = $this->generateHeaders();

        return isset($headers[strtolower($key)]);
    }

    /**
     * Get header
     *
     * Method for querying all values of a single HTTP header by its case-insensitive
     * name, following PSR-7. Returns a list of strings, or $default when not found.
     *
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    public function getHeader(string $key, array $default = []): array
    {
        $headers = $this->generateHeaders();

        return $headers[strtolower($key)] ?? $default;
    }

    /**
     * Get header line
     *
     * Returns all values for the given case-insensitive header name concatenated
     * using a comma, following PSR-7. Returns $default when the header is not found.
     */
    public function getHeaderLine(string $key, string $default = ''): string
    {
        $values = $this->getHeader($key);

        if ($values === []) {
            return $default;
        }

        return implode(', ', $values);
    }

    /**
     * Get headers
     *
     * Method for getting all HTTP headers as an associative array of header name
     * to a list of string values, following PSR-7.
     *
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->generateHeaders();
    }

    /**
     * Set header
     *
     * Replace any existing values for the given header with a single value,
     * mirroring PSR-7's withHeader.
     */
    public function setHeader(string $key, string $value): static
    {
        $this->generateHeaders();
        $this->headers[strtolower($key)] = [$value];

        return $this;
    }

    /**
     * Add header
     *
     * Append a value to an existing header, or create it if it does not exist,
     * mirroring PSR-7's withAddedHeader.
     */
    public function addHeader(string $key, string $value): static
    {
        $this->generateHeaders();
        $this->headers[strtolower($key)][] = $value;

        return $this;
    }

    /**
     * Remove header
     *
     * Method for removing an HTTP header by its case-insensitive name.
     */
    public function removeHeader(string $key): static
    {
        $this->generateHeaders();
        unset($this->headers[strtolower($key)]);

        return $this;
    }

    /**
     * Get Request Size
     *
     * Returns request size in bytes
     */
    public function getSize(): int
    {
        $headers = $this->generateHeaders();
        $headerStrings = [];
        foreach ($headers as $key => $values) {
            $headerStrings[] = $key . ': ' . implode(', ', $values);
        }
        return mb_strlen(implode("\n", $headerStrings), '8bit') + mb_strlen(file_get_contents('php://input') ?: '', '8bit');
    }

    /**
     * Get Content Range Start
     *
     * Returns the start of content range
     */
    public function getContentRangeStart(): ?int
    {
        $data = $this->parseContentRange();
        if (!empty($data)) {
            return $data['start'];
        }
        return null;
    }

    /**
     * Get Content Range End
     *
     * Returns the end of content range
     */
    public function getContentRangeEnd(): ?int
    {
        $data = $this->parseContentRange();
        if (!empty($data)) {
            return $data['end'];
        }
        return null;
    }

    /**
     * Get Content Range Size
     *
     * Returns the size of content range
     */
    public function getContentRangeSize(): ?int
    {
        $data = $this->parseContentRange();
        if (!empty($data)) {
            return $data['size'];
        }
        return null;
    }

    /**
     * Get Content Range Unit
     *
     * Returns the unit of content range
     */
    public function getContentRangeUnit(): ?string
    {
        $data = $this->parseContentRange();
        if (!empty($data)) {
            return $data['unit'];
        }
        return null;
    }

    /**
     * Get Range Start
     *
     * Returns the start of range header
     */
    public function getRangeStart(): ?int
    {
        $data = $this->parseRange();
        if (!empty($data)) {
            return $data['start'];
        }

        return null;
    }

    /**
     * Get Range End
     *
     * Returns the end of range header
     */
    public function getRangeEnd(): ?int
    {
        $data = $this->parseRange();
        if (!empty($data)) {
            return $data['end'];
        }

        return null;
    }

    /**
     * Get Range Unit
     *
     * Returns the unit of range header
     */
    public function getRangeUnit(): ?string
    {
        $data = $this->parseRange();
        if (!empty($data)) {
            return $data['unit'];
        }

        return null;
    }

    /**
     * Set query string parameters
     *
     * @param  array<string, mixed>  $params
     */
    public function setQueryString(array $params): static
    {
        $this->queryString = $params;

        return $this;
    }

    /**
     * Set payload parameters
     *
     * @param  array<string, mixed>  $params
     */
    public function setPayload(array $params): static
    {
        $this->payload = $params;

        return $this;
    }

    /**
     * Generate headers
     *
     * Parse request headers into a PSR-7 style map of lowercased header name to a
     * list of string values for easy querying using the getHeader method.
     *
     * @return array<string, array<int, string>>
     */
    protected function generateHeaders(): array
    {
        if (null === $this->headers) {
            $headers = [];

            /**
             * Fallback for environments
             * that do not support getallheaders
             */
            if (!\function_exists('getallheaders')) {
                foreach ($_SERVER as $name => $value) {
                    if (str_starts_with($name, 'HTTP_')) {
                        $key = str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))));
                        $headers[$key] = [(string) $value];
                    }
                }
            } else {
                foreach (getallheaders() as $name => $value) {
                    $headers[strtolower($name)] = [(string) $value];
                }
            }

            $this->headers = $headers;
        }

        return $this->headers;
    }

    /**
     * Generate cookies
     *
     * Parse request cookies into an associative array of cookie name to value.
     *
     * @return array<string, string>
     */
    abstract protected function generateCookies(): array;

    /**
     * Generate input
     *
     * Generate PHP input stream and parse it as an array in order to handle different content type of requests
     *
     * @return array<string, mixed>
     */
    abstract protected function generateInput(): array;

    /**
     * Decode a JSON request body into params.
     *
     * Params are associative arrays, but PHP has no empty associative array, so
     * `json_decode($body, true)` returns the same `[]` for `{}` and for `[]` and
     * re-encoding an empty object emits `[]`. Bodies that can contain an empty
     * object are decoded into objects instead, and only the empty ones are kept
     * as `stdClass`; everything else becomes the associative array the params
     * contract expects.
     *
     * @return array<string, mixed>
     */
    protected function decodePayload(string $raw): array
    {
        // An empty JSON object is always `{`, JSON whitespace, `}`, and `\s` covers
        // every JSON whitespace character, so no match means no empty object at any
        // depth. A `{}` inside a string literal matches and costs only the walk.
        $decoded = preg_match('/\{\s*\}/', $raw) === 0
            ? json_decode($raw, true)
            : $this->toAssociative(json_decode($raw));

        return \is_array($decoded) ? $decoded : [];
    }

    private function toAssociative(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = (array) $value;

            return $properties === [] ? $value : array_map($this->toAssociative(...), $properties);
        }

        if (\is_array($value)) {
            return array_map($this->toAssociative(...), $value);
        }

        return $value;
    }

    /**
     * Content Range Parser
     *
     * Parse content-range request header for easy access
     *
     * @return array{unit: string, size: int, start: int, end: int}|null
     */
    protected function parseContentRange(): ?array
    {
        $contentRange = $this->getHeaderLine('content-range', '');
        $data = [];
        if (!empty($contentRange)) {
            $contentRange = explode(' ', $contentRange);
            if (\count($contentRange) !== 2) {
                return null;
            }

            $data['unit'] = trim($contentRange[0]);

            if (empty($data['unit'])) {
                return null;
            }

            $rangeData = explode('/', $contentRange[1]);
            if (\count($rangeData) !== 2) {
                return null;
            }

            if (!ctype_digit($rangeData[1])) {
                return null;
            }

            $data['size'] = (int) $rangeData[1];
            $parts = explode('-', $rangeData[0]);
            if (\count($parts) !== 2) {
                return null;
            }

            if (!ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                return null;
            }

            $data['start'] = (int) $parts[0];
            $data['end'] = (int) $parts[1];
            if ($data['start'] > $data['end'] || $data['end'] > $data['size']) {
                return null;
            }

            return $data;
        }

        return null;
    }

    /**
     * Range Parser
     *
     * Parse range request header for easy access
     *
     * @return array<string, mixed>|null
     */
    protected function parseRange(): ?array
    {
        $rangeHeader = $this->getHeaderLine('range', '');
        if (empty($rangeHeader)) {
            return null;
        }

        $data = [];
        $ranges = explode('=', $rangeHeader);
        if (\count($ranges) !== 2 || empty($ranges[0]) || empty($ranges[1])) {
            return null;
        }
        $data['unit'] = $ranges[0];

        $ranges = explode('-', $ranges[1]);
        if (\count($ranges) !== 2 || $ranges[0] === '') {
            return null;
        }

        if (!ctype_digit($ranges[0])) {
            return null;
        }

        $data['start'] = (int) $ranges[0];

        if ($ranges[1] === '') {
            $data['end'] = null;
        } else {
            if (!ctype_digit($ranges[1])) {
                return null;
            }
            $data['end'] = (int) $ranges[1];
        }

        // RFC 9110 range bounds are inclusive, so start === end is a valid
        // single-byte range (`bytes=0-0`, or the final byte of a file).
        if ($data['end'] !== null && $data['start'] > $data['end']) {
            return null;
        }

        return $data;
    }
}
