<?php

namespace Utopia\Http\Adapter\FPM;

use Utopia\Http\Request as UtopiaRequest;
use Utopia\Http\TrustedHeaders;

class Request extends UtopiaRequest
{
    /**
     * Container for raw php://input parsed stream
     *
     * @var string
     */
    protected $rawPayload = '';

    public function __construct(TrustedHeaders $trusted = new TrustedHeaders())
    {
        $this->trusted = $trusted;
    }

    /**
     * Get raw payload
     *
     * Method for getting the HTTP request payload as a raw string.
     */
    public function getRawPayload(): string
    {
        $this->generateInput();

        return $this->rawPayload;
    }

    /**
     * Get server
     *
     * Method for querying server parameters. If $key is not found $default value will be returned.
     */
    public function getServer(string $key, ?string $default = null): ?string
    {
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Set server
     *
     * Method for setting server parameters.
     */
    public function setServer(string $key, string $value): static
    {
        $_SERVER[$key] = $value;

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
        $remoteAddr = $this->getServer('REMOTE_ADDR') ?? '0.0.0.0';

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
        return $this->trustedProtocol()
            ?? $this->getServer('REQUEST_SCHEME')
            ?? 'https';
    }

    /**
     * Get Port
     *
     * Returns request port.
     */
    public function getPort(): string
    {
        return (string) parse_url($this->getProtocol() . '://' . $this->getServer('HTTP_HOST', ''), PHP_URL_PORT);
    }

    /**
     * Get Hostname
     *
     * Returns request hostname.
     */
    public function getHostname(): string
    {
        $hostname = parse_url($this->getProtocol() . '://' . $this->getServer('HTTP_HOST', ''), PHP_URL_HOST);
        return strtolower((string) ($hostname));
    }

    /**
     * Get Method
     *
     * Return HTTP request method
     */
    public function getMethod(): string
    {
        return $this->getServer('REQUEST_METHOD') ?? 'UNKNOWN';
    }

    /**
     * Set Method
     *
     * Set HTTP request method
     */
    public function setMethod(string $method): static
    {
        $this->setServer('REQUEST_METHOD', $method);

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
        return $this->getServer('REQUEST_URI') ?? '';
    }

    /**
     * Get Path
     *
     * Return HTTP request path
     */
    public function setURI(string $uri): static
    {
        $this->setServer('REQUEST_URI', $uri);

        return $this;
    }

    /**
     * Get files
     *
     * Method for querying upload files data. If $key is not found empty array will be returned.
     *
     * @return array<string, mixed>
     */
    public function getFiles(string $key): array
    {
        return $_FILES[$key] ?? [];
    }

    /**
     * Get Referer
     *
     * Return HTTP referer header
     */
    public function getReferer(string $default = ''): string
    {
        return (string) $this->getServer('HTTP_REFERER', $default);
    }

    /**
     * Get Origin
     *
     * Return HTTP origin header
     */
    public function getOrigin(string $default = ''): string
    {
        return (string) $this->getServer('HTTP_ORIGIN', $default);
    }

    /**
     * Get User Agent
     *
     * Return HTTP user agent header
     */
    public function getUserAgent(string $default = ''): string
    {
        return (string) $this->getServer('HTTP_USER_AGENT', $default);
    }

    /**
     * Get Accept
     *
     * Return HTTP accept header
     */
    public function getAccept(string $default = ''): string
    {
        return (string) $this->getServer('HTTP_ACCEPT', $default);
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
            $this->cookies = $_COOKIE;
        }

        return $this->cookies;
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
            $this->queryString = $_GET;
        }
        if (null === $this->payload) {
            $contentType = $this->getHeaderLine('content-type');

            // Get content-type without the charset
            $length = strpos($contentType, ';');
            $length = (empty($length)) ? \strlen($contentType) : $length;
            $contentType = substr($contentType, 0, $length);

            $this->rawPayload = file_get_contents('php://input') ?: '';

            $this->payload = match ($contentType) {
                'application/json' => $this->decodePayload($this->rawPayload),
                default => $_POST,
            };

            if (empty($this->payload)) { // Make sure we return same data type even if json payload is empty or failed
                $this->payload = [];
            }
        }

        return match ($this->getServer('REQUEST_METHOD', '')) {
            self::METHOD_POST,
            self::METHOD_PUT,
            self::METHOD_PATCH,
            self::METHOD_DELETE => $this->payload,
            default => $this->queryString,
        };
    }
}
