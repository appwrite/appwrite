<?php

namespace Tests\E2E;

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

    /**
     * Service host name
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * SDK constructor.
     */
    public function __construct(string $baseUrl = 'http://fpm')
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], string $body = ''): array
    {
        if ($method === '') {
            throw new Exception('HTTP method is required');
        }

        usleep(50000);
        $ch = curl_init($this->baseUrl . $path . (($method === self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders = [];
        $responseStatus = -1;
        $responseBody = '';

        $cookies = [];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($body !== '') {
            // Sent verbatim, so a test can pin the exact bytes on the wire.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders, &$cookies) {
            $len = \strlen($header);
            $header = explode(':', $header, 2);

            if (\count($header) < 2) { // ignore invalid headers
                return $len;
            }

            if (strtolower(trim($header[0])) === 'set-cookie') {
                $parsed = $this->parseCookie((string) trim($header[1]));
                $name = array_key_first($parsed);
                $cookies[$name] = $parsed[$name];
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        $responseBody = curl_exec($ch);
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            echo 'Server error(' . $method . ': ' . $path . '. Params: ' . json_encode($params) . '): ' . json_encode($responseBody) . "\n";
        }

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'cookies' => $cookies,
        ];
    }

    /**
     * Parse Cookie String
     *
     * @return array<int|string, mixed>
     */
    public function parseCookie(string $cookie): array
    {
        $cookies = [];

        parse_str(strtr($cookie, ['&' => '%26', '+' => '%2B', ';' => '&']), $cookies);

        return $cookies;
    }

}
