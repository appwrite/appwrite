<?php

namespace Utopia\Http;

use Utopia\Compression\Algorithms\Brotli;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;

abstract class Response
{
    /**
     * HTTP content types
     */
    public const string CONTENT_TYPE_TEXT = 'text/plain';

    public const string CONTENT_TYPE_HTML = 'text/html';

    public const string CONTENT_TYPE_JSON = 'application/json';

    public const string CONTENT_TYPE_XML = 'text/xml';

    public const string CONTENT_TYPE_JAVASCRIPT = 'text/javascript';

    public const string CONTENT_TYPE_IMAGE = 'image/*';

    public const string CONTENT_TYPE_IMAGE_JPEG = 'image/jpeg';

    public const string CONTENT_TYPE_IMAGE_PNG = 'image/png';

    public const string CONTENT_TYPE_IMAGE_GIF = 'image/gif';

    public const string CONTENT_TYPE_IMAGE_SVG = 'image/svg+xml';

    public const string CONTENT_TYPE_IMAGE_WEBP = 'image/webp';

    public const string CONTENT_TYPE_IMAGE_ICON = 'image/x-icon';

    public const string CONTENT_TYPE_IMAGE_BMP = 'image/bmp';

    /**
     * Chrsets
     */
    public const string CHARSET_UTF8 = 'UTF-8';

    /**
     * HTTP response status codes
     */
    public const int STATUS_CODE_CONTINUE = 100;
    public const int STATUS_CODE_SWITCHING_PROTOCOLS = 101;
    public const int STATUS_CODE_PROCESSING = 102;
    public const int STATUS_CODE_EARLY_HINTS = 103;

    public const int STATUS_CODE_OK = 200;
    public const int STATUS_CODE_CREATED = 201;
    public const int STATUS_CODE_ACCEPTED = 202;
    public const int STATUS_CODE_NON_AUTHORITATIVE_INFORMATION = 203;
    public const int STATUS_CODE_NOCONTENT = 204;
    public const int STATUS_CODE_RESETCONTENT = 205;
    public const int STATUS_CODE_PARTIALCONTENT = 206;
    public const int STATUS_CODE_MULTI_STATUS = 207;
    public const int STATUS_CODE_ALREADY_REPORTED = 208;
    public const int STATUS_CODE_IM_USED = 226;

    public const int STATUS_CODE_MULTIPLE_CHOICES = 300;
    public const int STATUS_CODE_MOVED_PERMANENTLY = 301;
    public const int STATUS_CODE_FOUND = 302;
    public const int STATUS_CODE_SEE_OTHER = 303;
    public const int STATUS_CODE_NOT_MODIFIED = 304;
    public const int STATUS_CODE_USE_PROXY = 305;
    public const int STATUS_CODE_UNUSED = 306;
    public const int STATUS_CODE_TEMPORARY_REDIRECT = 307;
    public const int STATUS_CODE_PERMANENT_REDIRECT = 308;

    public const int STATUS_CODE_BAD_REQUEST = 400;
    public const int STATUS_CODE_UNAUTHORIZED = 401;
    public const int STATUS_CODE_PAYMENT_REQUIRED = 402;
    public const int STATUS_CODE_FORBIDDEN = 403;
    public const int STATUS_CODE_NOT_FOUND = 404;
    public const int STATUS_CODE_METHOD_NOT_ALLOWED = 405;
    public const int STATUS_CODE_NOT_ACCEPTABLE = 406;
    public const int STATUS_CODE_PROXY_AUTHENTICATION_REQUIRED = 407;
    public const int STATUS_CODE_REQUEST_TIMEOUT = 408;
    public const int STATUS_CODE_CONFLICT = 409;
    public const int STATUS_CODE_GONE = 410;
    public const int STATUS_CODE_LENGTH_REQUIRED = 411;
    public const int STATUS_CODE_PRECONDITION_FAILED = 412;
    public const int STATUS_CODE_REQUEST_ENTITY_TOO_LARGE = 413;
    public const int STATUS_CODE_REQUEST_URI_TOO_LONG = 414;
    public const int STATUS_CODE_UNSUPPORTED_MEDIA_TYPE = 415;
    public const int STATUS_CODE_REQUESTED_RANGE_NOT_SATISFIABLE = 416;
    public const int STATUS_CODE_EXPECTATION_FAILED = 417;
    public const int STATUS_CODE_IM_A_TEAPOT = 418;
    public const int STATUS_CODE_MISDIRECTED_REQUEST = 421;
    public const int STATUS_CODE_UNPROCESSABLE_ENTITY = 422;
    public const int STATUS_CODE_LOCKED = 423;
    public const int STATUS_CODE_FAILED_DEPENDENCY = 424;
    public const int STATUS_CODE_TOO_EARLY = 425;
    public const int STATUS_CODE_UPGRADE_REQUIRED = 426;
    public const int STATUS_CODE_PRECONDITION_REQUIRED = 428;
    public const int STATUS_CODE_TOO_MANY_REQUESTS = 429;
    public const int STATUS_CODE_REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    public const int STATUS_CODE_UNAVAILABLE_FOR_LEGAL_REASONS = 451;

    public const int STATUS_CODE_INTERNAL_SERVER_ERROR = 500;
    public const int STATUS_CODE_NOT_IMPLEMENTED = 501;
    public const int STATUS_CODE_BAD_GATEWAY = 502;
    public const int STATUS_CODE_SERVICE_UNAVAILABLE = 503;
    public const int STATUS_CODE_GATEWAY_TIMEOUT = 504;
    public const int STATUS_CODE_HTTP_VERSION_NOT_SUPPORTED = 505;
    public const int STATUS_CODE_VARIANT_ALSO_NEGOTIATES = 506;
    public const int STATUS_CODE_INSUFFICIENT_STORAGE = 507;
    public const int STATUS_CODE_LOOP_DETECTED = 508;
    public const int STATUS_CODE_NOT_EXTENDED = 510;
    public const int STATUS_CODE_NETWORK_AUTHENTICATION_REQUIRED = 511;

    /**
     * @var array<int, string>
     */
    protected $statusCodes = [
        self::STATUS_CODE_CONTINUE => 'Continue',
        self::STATUS_CODE_SWITCHING_PROTOCOLS => 'Switching Protocols',
        self::STATUS_CODE_PROCESSING => 'Processing',
        self::STATUS_CODE_EARLY_HINTS => 'Early Hints',
        self::STATUS_CODE_OK => 'OK',
        self::STATUS_CODE_CREATED => 'Created',
        self::STATUS_CODE_ACCEPTED => 'Accepted',
        self::STATUS_CODE_NON_AUTHORITATIVE_INFORMATION => 'Non-Authoritative Information',
        self::STATUS_CODE_NOCONTENT => 'No Content',
        self::STATUS_CODE_RESETCONTENT => 'Reset Content',
        self::STATUS_CODE_PARTIALCONTENT => 'Partial Content',
        self::STATUS_CODE_MULTI_STATUS => 'Multi-Status',
        self::STATUS_CODE_ALREADY_REPORTED => 'Already Reported',
        self::STATUS_CODE_IM_USED => 'IM Used',
        self::STATUS_CODE_MULTIPLE_CHOICES => 'Multiple Choices',
        self::STATUS_CODE_MOVED_PERMANENTLY => 'Moved Permanently',
        self::STATUS_CODE_FOUND => 'Found',
        self::STATUS_CODE_SEE_OTHER => 'See Other',
        self::STATUS_CODE_NOT_MODIFIED => 'Not Modified',
        self::STATUS_CODE_USE_PROXY => 'Use Proxy',
        self::STATUS_CODE_UNUSED => '(Unused)',
        self::STATUS_CODE_TEMPORARY_REDIRECT => 'Temporary Redirect',
        self::STATUS_CODE_PERMANENT_REDIRECT => 'Permanent Redirect',
        self::STATUS_CODE_BAD_REQUEST => 'Bad Request',
        self::STATUS_CODE_UNAUTHORIZED => 'Unauthorized',
        self::STATUS_CODE_PAYMENT_REQUIRED => 'Payment Required',
        self::STATUS_CODE_FORBIDDEN => 'Forbidden',
        self::STATUS_CODE_NOT_FOUND => 'Not Found',
        self::STATUS_CODE_METHOD_NOT_ALLOWED => 'Method Not Allowed',
        self::STATUS_CODE_NOT_ACCEPTABLE => 'Not Acceptable',
        self::STATUS_CODE_PROXY_AUTHENTICATION_REQUIRED => 'Proxy Authentication Required',
        self::STATUS_CODE_REQUEST_TIMEOUT => 'Request Timeout',
        self::STATUS_CODE_CONFLICT => 'Conflict',
        self::STATUS_CODE_GONE => 'Gone',
        self::STATUS_CODE_LENGTH_REQUIRED => 'Length Required',
        self::STATUS_CODE_PRECONDITION_FAILED => 'Precondition Failed',
        self::STATUS_CODE_REQUEST_ENTITY_TOO_LARGE => 'Request Entity Too Large',
        self::STATUS_CODE_REQUEST_URI_TOO_LONG => 'Request-URI Too Long',
        self::STATUS_CODE_UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
        self::STATUS_CODE_REQUESTED_RANGE_NOT_SATISFIABLE => 'Requested Range Not Satisfiable',
        self::STATUS_CODE_EXPECTATION_FAILED => 'Expectation Failed',
        self::STATUS_CODE_IM_A_TEAPOT => 'I\'m a teapot',
        self::STATUS_CODE_MISDIRECTED_REQUEST => 'Misdirected Request',
        self::STATUS_CODE_UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
        self::STATUS_CODE_LOCKED => 'Locked',
        self::STATUS_CODE_FAILED_DEPENDENCY => 'Failed Dependency',
        self::STATUS_CODE_TOO_EARLY => 'Too Early',
        self::STATUS_CODE_UPGRADE_REQUIRED => 'Upgrade Required',
        self::STATUS_CODE_PRECONDITION_REQUIRED => 'Precondition Required',
        self::STATUS_CODE_TOO_MANY_REQUESTS => 'Too Many Requests',
        self::STATUS_CODE_REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large',
        self::STATUS_CODE_UNAVAILABLE_FOR_LEGAL_REASONS => 'Unavailable For Legal Reasons',
        self::STATUS_CODE_INTERNAL_SERVER_ERROR => 'Internal Server Error',
        self::STATUS_CODE_NOT_IMPLEMENTED => 'Not Implemented',
        self::STATUS_CODE_BAD_GATEWAY => 'Bad Gateway',
        self::STATUS_CODE_SERVICE_UNAVAILABLE => 'Service Unavailable',
        self::STATUS_CODE_GATEWAY_TIMEOUT => 'Gateway Timeout',
        self::STATUS_CODE_HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version Not Supported',
        self::STATUS_CODE_VARIANT_ALSO_NEGOTIATES => 'Variant Also Negotiates',
        self::STATUS_CODE_INSUFFICIENT_STORAGE => 'Insufficient Storage',
        self::STATUS_CODE_LOOP_DETECTED => 'Loop Detected',
        self::STATUS_CODE_NOT_EXTENDED => 'Not Extended',
        self::STATUS_CODE_NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required',
    ];

    /**
     * Mime Types with compression support
     *
     * @var array<string, bool>
     */
    private static $compressible = [
        // Text
        'text/html' => true,
        'text/richtext' => true,
        'text/plain' => true,
        'text/css' => true,
        'text/x-script' => true,
        'text/x-component' => true,
        'text/x-java-source' => true,
        'text/x-markdown' => true,

        // JavaScript
        'application/javascript' => true,
        'application/x-javascript' => true,
        'text/javascript' => true,
        'text/js' => true,

        // Icons
        'image/x-icon' => true,
        'image/vnd.microsoft.icon' => true,

        // Scripts
        'application/x-perl' => true,
        'application/x-httpd-cgi' => true,

        // XML and JSON
        'text/xml' => true,
        'application/xml' => true,
        'application/rss+xml' => true,
        'application/vnd.api+json' => true,
        'application/x-protobuf' => true,
        'application/json' => true,
        'application/manifest+json' => true,
        'application/ld+json' => true,
        'application/graphql+json' => true,
        'application/geo+json' => true,

        // Multipart
        'multipart/bag' => true,
        'multipart/mixed' => true,

        // XHTML
        'application/xhtml+xml' => true,

        // Fonts
        'font/ttf' => true,
        'font/otf' => true,
        'font/x-woff' => true,
        'image/svg+xml' => true,
        'application/vnd.ms-fontobject' => true,
        'application/ttf' => true,
        'application/x-ttf' => true,
        'application/otf' => true,
        'application/x-otf' => true,
        'application/truetype' => true,
        'application/opentype' => true,
        'application/x-opentype' => true,
        'application/font-woff' => true,
        'application/eot' => true,
        'application/font' => true,
        'application/font-sfnt' => true,

        // WebAssembly
        'application/wasm' => true,
        'application/javascript-binast' => true,
    ];

    public const string COOKIE_SAMESITE_NONE = 'None';

    public const string COOKIE_SAMESITE_STRICT = 'Strict';

    public const string COOKIE_SAMESITE_LAX = 'Lax';

    public const int CHUNK_SIZE = 2000000; //2mb
    protected int $statusCode = self::STATUS_CODE_OK;

    protected string $contentType = '';

    protected bool $disablePayload = false;

    protected bool $sent = false;

    protected bool $headersSent = false;

    /**
     * Response headers, stored under their lowercased name and mapping to a list of
     * one or more string values, following the PSR-7 header representation.
     *
     * @var array<string, array<int, string>>
     */
    protected array $headers = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $cookies = [];

    protected float $startTime = 0;

    protected int $size = 0;

    protected string $acceptEncoding = '';

    protected int $compressionMinSize = Http::COMPRESSION_MIN_SIZE_DEFAULT;

    protected mixed $compressionSupported = [];

    /**
     * Response constructor.
     *
     * @param  float  $time response start time
     */
    public function __construct(float $time = 0)
    {
        $this->startTime = (!empty($time)) ? $time : microtime(true);
    }

    private function isCompressible(?string $contentType): bool
    {
        if (!$contentType) {
            return false;
        }

        // Strip any parameters (e.g. ;charset=utf-8)
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        return isset(self::$compressible[$contentType]);
    }

    /**
     * Set accept encoding
     *
     * Set HTTP accept encoding header.
     */
    public function setAcceptEncoding(string $acceptEncoding): static
    {
        $this->acceptEncoding = $acceptEncoding;

        return $this;
    }

    /**
     * Set min compression size
     *
     * Set minimum size for compression to be applied in bytes.
     */
    public function setCompressionMinSize(int $compressionMinSize): static
    {
        $this->compressionMinSize = $compressionMinSize;

        return $this;
    }

    /**
     * Set supported compression algorithms
     */
    public function setCompressionSupported(mixed $compressionSupported): static
    {
        $this->compressionSupported = $compressionSupported;

        return $this;
    }

    /**
     * Set content type
     *
     * Set HTTP content type header.
     */
    public function setContentType(string $type, string $charset = ''): static
    {
        $this->contentType = $type . ((!empty($charset) ? '; charset=' . $charset : ''));

        return $this;
    }

    /**
     * Get content type
     *
     * Get HTTP content type header.
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Get if response was already sent
     */
    public function isSent(): bool
    {
        return $this->sent;
    }

    /**
     * Set status code
     *
     * Set HTTP response status code between available options. if status code is unknown an exception will be thrown
     *
     *
     * @throws Exception
     */
    public function setStatusCode(int $code = 200): static
    {
        if (!\array_key_exists($code, $this->statusCodes)) {
            throw new Exception('Unknown HTTP status code');
        }

        $this->statusCode = $code;

        return $this;
    }

    /**
     * Get status code
     *
     * Get HTTP response status code
     **/
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get Response Size
     *
     * Return output response size in bytes
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Don't allow payload on response output
     */
    public function disablePayload(): static
    {
        $this->disablePayload = true;

        return $this;
    }

    /**
     * Allow payload on response output
     */
    public function enablePayload(): static
    {
        $this->disablePayload = false;

        return $this;
    }

    /**
     * Set header
     *
     * Replace any existing values for the given header with a single value,
     * mirroring PSR-7's withHeader.
     */
    public function setHeader(string $key, string $value): static
    {
        $this->headers[strtolower($key)] = [$value];

        return $this;
    }

    /**
     * Add header
     *
     * Append a value to an existing response header, or create it if it does not
     * exist, mirroring PSR-7's withAddedHeader.
     */
    public function addHeader(string $key, string $value): static
    {
        $this->headers[strtolower($key)][] = $value;

        return $this;
    }

    /**
     * Remove header
     *
     * Remove an HTTP response header by its case-insensitive name.
     */
    public function removeHeader(string $key): static
    {
        unset($this->headers[strtolower($key)]);

        return $this;
    }

    /**
     * Has header
     *
     * Checks if a response header exists by the given case-insensitive name, following PSR-7.
     */
    public function hasHeader(string $key): bool
    {
        return isset($this->headers[strtolower($key)]);
    }

    /**
     * Get header
     *
     * Return all values of a single response header by its case-insensitive name,
     * following PSR-7. Returns a list of strings, or $default when not found.
     *
     * @param  array<int, string>  $default
     * @return array<int, string>
     */
    public function getHeader(string $key, array $default = []): array
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * Get header line
     *
     * Return all values for the given case-insensitive header name concatenated
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
     * Get Headers
     *
     * Return array of all response headers, each mapping to a list of string values,
     * following PSR-7.
     *
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Add cookie
     *
     * Add an HTTP cookie to response header
     */
    public function addCookie(string $name, ?string $value = null, ?int $expire = null, ?string $path = null, ?string $domain = null, ?bool $secure = null, ?bool $httponly = null, ?string $sameSite = null): static
    {
        $name = strtolower($name);

        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $sameSite,
        ];

        return $this;
    }

    /**
     * Remove cookie
     *
     * Remove HTTP response cookie
     */
    public function removeCookie(string $name): static
    {
        $this->cookies = array_filter($this->cookies, fn($cookie) => $cookie['name'] !== $name);

        return $this;
    }

    /**
     * Get Cookies
     *
     * Return array of all response cookies
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Output response
     *
     * Generate HTTP response output including the response header (+cookies) and body and prints them.
     */
    public function send(string $body = ''): void
    {
        if ($this->sent) {
            return;
        }

        $this->appendCookies();

        // Compress body only if all conditions are met:
        if (
            !$this->hasHeader('Content-Encoding')
            && !empty($this->acceptEncoding)
            && $this->isCompressible($this->contentType)
            && \strlen($body) > $this->compressionMinSize
        ) {
            $algorithm = Compression::fromAcceptEncoding($this->acceptEncoding, $this->compressionSupported);

            if ($algorithm) {
                if ($algorithm instanceof Brotli) {
                    $algorithm->setLevel(Http::COMPRESSION_BROTLI_LEVEL_DEFAULT);
                } elseif ($algorithm instanceof Zstd) {
                    $algorithm->setLevel(Http::COMPRESSION_ZSTD_LEVEL_DEFAULT);
                }

                $body = $algorithm->compress($body);
                $this->removeHeader('Content-Length');
                $this->addHeader('Content-Length', (string) \strlen($body));
                $this->addHeader('Content-Encoding', $algorithm->getContentEncoding());
                $this->addHeader('X-Utopia-Compression', 'true');
                $this->addHeader('Vary', 'Accept-Encoding');
            }
        }

        $this->addHeader('X-Debug-Speed', (string) (microtime(true) - $this->startTime));
        $this->appendHeaders();

        // Send response
        if ($this->disablePayload) {
            $this->end();
            $this->sent = true;

            return;
        }

        $headersSize = 0;
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $headersSize += \strlen($name . ': ' . $value);
            }
            $headersSize += (\count($values) - 1) * 2; // linebreaks
        }
        $headersSize += (\count($this->headers) - 1) * 2; // linebreaks

        $bodyLength = \strlen($body);
        $this->size += $headersSize + $bodyLength;

        if ($bodyLength <= self::CHUNK_SIZE) {
            $this->end($body);
        } else {
            $chunks = str_split($body, self::CHUNK_SIZE);
            foreach ($chunks as $chunk) {
                $this->write($chunk);
            }
            $this->end();
        }

        $this->sent = true;

        $this->disablePayload();
    }

    /**
     * Write
     *
     * Send output
     *
     * @return bool False if write cannot complete, such as request ended by client
     */
    abstract public function write(string $content): bool;

    /**
     * End
     *
     * Send optional content and end
     */
    abstract public function end(?string $content = null): void;

    /**
     * Output response
     *
     * Generate HTTP response output including the response header (+cookies) and body and prints them.
     *
     *
     */
    public function chunk(string $body = '', bool $end = false): void
    {
        if ($this->sent) {
            return;
        }

        if ($end) {
            $this->sent = true;
        }

        $this->addHeader('X-Debug-Speed', (string) (microtime(true) - $this->startTime));

        if (!$this->headersSent) {
            $this
                ->appendCookies()
                ->appendHeaders();

            $this->headersSent = true;
        }

        if (!$this->disablePayload) {
            $this->write($body);
            if ($end) {
                $this->disablePayload();
                $this->end();
            }
        } else {
            $this->end();
        }
    }

    /**
     * Append headers
     *
     * Iterating over response headers to generate them using native PHP header function.
     * This method is also responsible for generating the response and content type headers.
     */
    protected function appendHeaders(): static
    {
        // Send status code header
        $this->sendStatus($this->statusCode);

        // Send content type header
        if (!empty($this->contentType)) {
            $this->setHeader('Content-Type', $this->contentType);
        }

        // Set application headers
        foreach ($this->headers as $key => $values) {
            $this->sendHeader($key, $values);
        }

        return $this;
    }

    /**
     * Send Status Code
     */
    abstract protected function sendStatus(int $statusCode): void;

    /**
     * Send Header
     *
     * Output Header
     *
     * @param  array<int, string>  $value
     */
    abstract public function sendHeader(string $key, array $value): void;

    /**
     * Send Cookie
     *
     * Output Cookie
     *
     * @param  array<string, mixed>  $options
     */
    abstract protected function sendCookie(string $name, string $value, array $options): void;

    /**
     * Append cookies
     *
     * Iterating over response cookies to generate them using native PHP cookie function.
     */
    protected function appendCookies(): static
    {
        foreach ($this->cookies as $cookie) {
            // A cookie may be registered without a value; send an empty string
            // rather than passing null to the non-nullable $value parameter.
            $this->sendCookie($cookie['name'], $cookie['value'] ?? '', [
                'expire' => $cookie['expire'],
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'],
            ]);
        }

        return $this;
    }

    /**
     * Redirect
     *
     * This helper is for sending a 30* HTTP response.
     * After setting relevant HTTP headers for redirect response this helper stop application native flow what means the shutdown method will not be executed
     *
     * NOTICE: it seems webkit based browsers have problems redirecting link with 300 status codes.
     *
     * @see https://code.google.com/p/chromium/issues/detail?id=75540
     * @see https://bugs.webkit.org/show_bug.cgi?id=47425
     *
     * @param  string  $url complete absolute URI for redirection as required by the internet standard RFC 2616 (HTTP 1.1)
     * @param  int  $statusCode valid HTTP status code
     *
     * @throws Exception
     * @see http://tools.ietf.org/html/rfc2616
     */
    public function redirect(string $url, int $statusCode = 301): void
    {
        if (300 === $statusCode) {
            trigger_error('It seems webkit based browsers have problems redirecting link with 300 status codes!', E_USER_NOTICE);
        }

        $this
            ->addHeader('Location', $url)
            ->setStatusCode($statusCode)
            ->send('');
    }

    /**
     * HTML
     *
     * This helper is for sending an HTML HTTP response and sets relevant content type header ('text/html').
     *
     * @see http://en.wikipedia.org/wiki/JSON
     */
    public function html(string $data): void
    {
        $this
            ->setContentType(self::CONTENT_TYPE_HTML, self::CHARSET_UTF8)
            ->send($data);
    }

    /**
     * Text
     *
     * This helper is for sending plain text HTTP response and sets relevant content type header ('text/plain').
     *
     * @see http://en.wikipedia.org/wiki/JSON
     */
    public function text(string $data): void
    {
        $this
            ->setContentType(self::CONTENT_TYPE_TEXT, self::CHARSET_UTF8)
            ->send($data);
    }

    /**
     * JSON
     *
     * This helper is for sending JSON HTTP response.
     * It sets relevant content type header ('application/json') and convert a PHP array ($data) to valid JSON using native json_encode
     *
     * @see http://en.wikipedia.org/wiki/JSON
     *
     * @param  mixed  $data
     */
    public function json($data): void
    {
        if (!\is_array($data) && !$data instanceof \stdClass) {
            throw new \Exception('Invalid JSON input var');
        }

        $this
            ->setContentType(Response::CONTENT_TYPE_JSON, self::CHARSET_UTF8)
            ->send(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * JSON with padding
     *
     * This helper is for sending JSONP HTTP response.
     * It sets relevant content type header ('text/javascript') and convert a PHP array ($data) to valid JSON using native json_encode
     *
     * @see http://en.wikipedia.org/wiki/JSONP
     *
     * @param  array<string, mixed>  $data
     */
    public function jsonp(string $callback, array $data): void
    {
        $this
            ->setContentType(self::CONTENT_TYPE_JAVASCRIPT, self::CHARSET_UTF8)
            ->send('parent.' . $callback . '(' . json_encode($data) . ');');
    }

    /**
     * Iframe
     *
     * This helper is for sending iframe HTTP response.
     * It sets relevant content type header ('text/html') and convert a PHP array ($data) to valid JSON using native json_encode
     *
     * @param  array<string, mixed>  $data
     */
    public function iframe(string $callback, array $data): void
    {
        $this
            ->setContentType(self::CONTENT_TYPE_HTML, self::CHARSET_UTF8)
            ->send('<script type="text/javascript">window.parent.' . $callback . '(' . json_encode($data) . ');</script>');
    }

    /**
     * No Content
     *
     * This helper is for sending no content HTTP response.
     *
     * The server has successfully fulfilled the request
     *  and that there is no additional content to send in the response payload body.
     */
    public function noContent(): void
    {
        $this
            ->setStatusCode(self::STATUS_CODE_NOCONTENT)
            ->send('');
    }
}
