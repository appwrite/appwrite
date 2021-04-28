<?php

namespace Tests\E2E;

use Exception;

class Client
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';
    const METHOD_HEAD = 'HEAD';
    const METHOD_OPTIONS = 'OPTIONS';
    const METHOD_CONNECT = 'CONNECT';
    const METHOD_TRACE = 'TRACE';

    /**
     * Is Self Signed Certificates Allowed?
     *
     * @var bool
     */
    protected $selfSigned = false;

    /**
     * Service host name
     *
     * @var string
     */
    protected $endpoint = 'https://appwrite.test/v1';

    /**
     * Global Headers
     *
     * @var array
     */
    protected $headers = [
        'content-type' => '',
        'x-sdk-version' => 'appwrite:php:v1.0.7',
    ];

    /**
     * SDK constructor.
     */
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
     * Your Appwrite project secret key. You can can create a new API key from your Appwrite console API keys dashboard.
     *
     * @param string $value
     *
     * @return self $this
     */
    public function setKey(string $value): self
    {
        $this->addHeader('X-Appwrite-Key', $value);

        return $this;
    }

    /**
     * Set Locale
     *
     * @param string $value
     *
     * @return self $this
     */
    public function setLocale(string $value): self
    {
        $this->addHeader('X-Appwrite-Locale', $value);

        return $this;
    }

    /**
     * Set Mode
     *
     * @param string $value
     *
     * @return self $this
     */
    public function setMode(string $value): self
    {
        $this->addHeader('X-Appwrite-Mode', $value);

        return $this;
    }

    /**
     * @param bool $status true
     * @return self $this
     */
    public function setSelfSigned(bool $status = true): self
    {
        $this->selfSigned = $status;

        return $this;
    }

    /**
     * @param mixed $endpoint
     * @return self $this
     */
    public function setEndpoint($endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return self $this
     */
    public function addHeader(string $key, string $value): self
    {
        $this->headers[strtolower($key)] = strtolower($value);

        return $this;
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param array $headers
     * @return array|string
     * @throws Exception
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [])
    {
        sleep(0.5);
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders    = [];
        $responseStatus     = -1;
        $responseType       = '';
        $responseBody       = '';

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != self::METHOD_GET) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        // Allow self signed certificates
        if ($this->selfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $responseBody   = curl_exec($ch);
        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch (substr($responseType, 0, strpos($responseType, ';'))) {
            case 'application/json':
                $json = json_decode($responseBody, true);

                if ($json === null) {
                    throw new Exception('Failed to parse response: '.$responseBody);
                }

                $responseBody = $json;
                $json = null;
            break;
        }

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            echo 'Server error('.$method.': '.$path.'. Params: '.json_encode($params).'): '.json_encode($responseBody)."\n";
        }

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Parse Cookie String
     *
     * @param string $cookie
     * @return array
     */
    public function parseCookie(string $cookie): array
    {
        $cookies = [];

        parse_str(strtr($cookie, array('&' => '%26', '+' => '%2B', ';' => '&')), $cookies);

        return $cookies;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param array $data
     * @param string $prefix
     * @return array
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
