<?php

namespace Tests\E2E;

use Appwrite\Utopia\Fetch\BodyMultipart;
use Exception;

class Client
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';
    public const METHOD_CONNECT = 'CONNECT';
    public const METHOD_TRACE = 'TRACE';

    
    protected $selfSigned = false;

    protected $endpoint = 'https://appwrite.test/v1';

    
    protected $headers = [
        'content-type' => '',
        'x-sdk-version' => 'appwrite:php:v1.0.7',
    ];

    
    public function __construct()
    {
    }

    /**
     * Set Project
     *
     * Your Appwrite project ID. You can find your project ID in your Appwrite console project settings.
     *
     * @param string $value
     *
     * @return self $this
     */
    public function setProject(string $value): self
    {
        $this->addHeader('X-Appwrite-Project', $value);

        return $this;
    }

    /**
     * Set Key
     *
     * Your Appwrite project secret key. You can create a new API key from your Appwrite console API keys dashboard.
     *
     * @param string $value The API key for authentication
     *
     * @return self $this Returns the current instance for method chaining
     */
    public function setKey(string $value): self
    {
        $this->addHeader('X-Appwrite-Key', $value);

        return $this;
    }

    /**
     * Set Locale
     *
     * Sets the locale for internationalization and localization purposes.
     *
     * @param string $value The locale code (e.g., 'en-US', 'fr-FR')
     *
     * @return self $this Returns the current instance for method chaining
     */
    public function setLocale(string $value): self
    {
        $this->addHeader('X-Appwrite-Locale', $value);

        return $this;
    }

    /**
     * Set Mode
     *
     * Sets the execution mode for the SDK (e.g., 'development', 'production').
     *
     * @param string $value The mode to set
     *
     * @return self $this Returns the current instance for method chaining
     */
    public function setMode(string $value): self
    {
        $this->addHeader('X-Appwrite-Mode', $value);

        return $this;
    }

    /**
     * Set Response Format
     *
     * Sets the desired response format for API responses.
     *
     * @param string $value The response format (e.g., 'json', 'xml')
     *
     * @return self $this Returns the current instance for method chaining
     */
    public function setResponseFormat(string $value): self
    {
        $this->addHeader('X-Appwrite-Response-Format', $value);

        return $this;
    }

    /**
     * Set Self Signed Certificates
     *
     * Enable or disable SSL certificate verification for self-signed certificates.
     * Use this only in development environments.
     *
     * @param bool $status Whether to allow self-signed certificates (default: true)
     * @return self $this Returns the current instance for method chaining
     */
    public function setSelfSigned(bool $status = true): self
    {
        $this->selfSigned = $status;

        return $this;
    }

    /**
     * Set Endpoint
     *
     * Sets the API endpoint URL for all subsequent requests.
     *
     * @param string $endpoint The base URL for the Appwrite API
     * @return self $this Returns the current instance for method chaining
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Get Endpoint
     *
     * Returns the currently configured API endpoint URL.
     *
     * @return string The current endpoint URL
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Add Header
     *
     * Adds a custom header to all subsequent requests.
     *
     * @param string $key The header name
     * @param string $value The header value
     *
     * @return self $this Returns the current instance for method chaining
     */
    public function addHeader(string $key, string $value): self
    {
        $this->headers[strtolower($key)] = strtolower($value);

        return $this;
    }

    /**
     * Call
     *
     * Make an API call to the Appwrite server.
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE, etc.)
     * @param string $path API endpoint path (relative to base URL)
     * @param array $headers Additional headers for this request
     * @param mixed $params Request parameters or body content
     * @param bool $decode Whether to decode the response body (default: true)
     * @param bool $followRedirects Whether to follow HTTP redirects (default: true)
     * @return array Response containing headers, cookies, and body
     * @throws Exception When the request fails or response is invalid
     */
    public function call(string $method, string $path = '', array $headers = [], mixed $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders    = [];
        $cookies = [];

        $query = match ($headers['content-type']) {
            'application/json' => json_encode($params),
            'multipart/form-data' => $this->flatten($params),
            'application/graphql' => $params[0],
            'text/plain' => $params,
            default => http_build_query($params),
        };

        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'accept-encoding') {
                curl_setopt($ch, CURLOPT_ENCODING, $value);
                continue;
            } else {
                $formattedHeaders[] = $key . ': ' . $value;
            }
        }

        curl_setopt($ch, CURLOPT_PATH_AS_IS, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders, &$cookies) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            if (strtolower(trim($header[0])) == 'set-cookie') {
                $parsed = $this->parseCookie((string)trim($header[1]));
                $name = array_key_first($parsed);
                $cookies[$name] = $parsed[$name];
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });


        if ($method === self::METHOD_HEAD) {
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
        } else {
            curl_setopt($ch, CURLOPT_NOBODY, false);
        }

        // if ($method != self::METHOD_GET && $method != self::METHOD_HEAD) {
        if (!in_array($method, [self::METHOD_GET, self::METHOD_HEAD], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        // Allow self-signed certificates
        if ($this->selfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        $responseBody   = curl_exec($ch);
        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($decode && $method !== self::METHOD_HEAD) {
            $strpos = strpos($responseType, ';');
            $strpos = \is_bool($strpos) ? \strlen($responseType) : $strpos;
            switch (substr($responseType, 0, $strpos)) {
                case 'multipart/form-data':
                    $boundary = \explode('boundary=', $responseHeaders['content-type'] ?? '')[1] ?? '';
                    $multipartResponse = new BodyMultipart($boundary);
                    $multipartResponse->load(\is_bool($responseBody) ? '' : $responseBody);

                    $responseBody = $multipartResponse->getParts();
                    break;
                case 'application/json':
                    if (\is_bool($responseBody)) {
                        throw new Exception('Response is not a valid JSON.');
                    }

                    $json = json_decode($responseBody, true);

                    if ($json === null) {
                        throw new Exception('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        } elseif ($method === self::METHOD_HEAD) {
            // For HEAD requests, always set body to empty string regardless of decode flag
            $responseBody = '';
        }

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            // echo 'Server error(' . $method . ': ' . $path . '. Params: ' . json_encode($params) . '): ' . json_encode($responseBody) . '\n';
            error_log('Server error(' . $method . ': ' . $path . '. Params: ' . json_encode($params) . '): ' . json_encode($responseBody));
        }

        return [
            'headers' => $responseHeaders,
            'cookies' => $cookies,
            'body' => $responseBody
        ];
    }

    /**
     * Parse Cookie String
     *
     * Parses a cookie header string into an associative array.
     *
     * @param string $cookie The cookie string to parse
     * @return array Parsed cookie data as associative array
     */
    public function parseCookie(string $cookie): array
    {
        $cookies = [];

        parse_str(strtr($cookie, ['&' => '%26', '+' => '%2B', ';' => '&']), $cookies);

        return $cookies;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * Converts nested arrays into PHP-compatible multipart format.
     * Handles name collision prevention for nested structures.
     *
     * @param array $data The data array to flatten
     * @param string $prefix The prefix for nested keys (used internally)
     * @return array Flattened array suitable for HTTP requests
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey);
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
