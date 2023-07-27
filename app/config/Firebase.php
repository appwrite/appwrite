<?php

class Firebase
{
    protected string $endpoint = 'https://firebase.googleapis.com/';

    protected string $accessToken;

    protected string $refreshToken;

    protected string $clientId;

    protected $cache;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct($cache)
    {
        $this->cache = $cache;
    }


    /**
     * Firebase Initialisation with access token generation.
     */
    public function initialiseVariables(string $clientId, string $clientSecret): void
    {
        $this->clientId = $clientId;

        $response = $this->cache->load($clientId, 60 * 9); // 10 minutes, but 1 minute earlier to be safe
        if ($response == false) {
            $this->generateAccessToken($clientSecret, $clientId, $this->refreshToken);

            $tokens = \json_encode([
                'refrershToken' => $this->refreshToken,
                'accessToken' => $this->accessToken,
            ]) ?: '{}';

            $this->cache->save($clientId, $tokens);
        } else {
            $parsed = \json_decode($response, true);
            $this->refreshToken = $parsed['refresh_token'];
            $this->accessToken = $parsed['access_token'];
        }
    }

    /**
     * Generate Access Token
     */
    protected function generateAccessToken(string $privateKey, string $clientId, string $token): void
    {
        $res = $this->call('POST', 'https://oauth2.googleapis.com/token', [], [
            'client_id' => $clientId,
            'client_secret' => $privateKey,
            'refresh_token' => $token,
            'grant_type' => 'refresh_token',
        ]);
        $this->accessToken = $res['body']['access_token'];
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<mixed>  $params
     * @param  array<string, string>  $headers
     * @param  bool  $decode
     * @return array<mixed>
     *
     * @throws Exception
     */
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true)
    {
        $headers = array_merge($this->headers, $headers);
        $ch = curl_init($this->endpoint . $path . (($method == 'GET' && !empty($params)) ? '?' . http_build_query($params) : ''));

        if (!$ch) {
            throw new Exception('Curl failed to initialize');
        }

        $responseHeaders = [];
        $responseStatus = -1;
        $responseType = '';
        $responseBody = '';

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            case 'application/graphql':
                $query = $params[0];
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_PATH_AS_IS, 1);
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

        if ($method != 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        $responseBody = \curl_exec($ch) ?: '';

        if ($responseBody === true) {
            $responseBody = '';
        }

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($decode) {
            $length = strpos($responseType, ';') ?: 0;
            switch (substr($responseType, 0, $length)) {
                case 'application/json':
                    $json = \json_decode($responseBody, true);

                    if ($json === null) {
                        throw new Exception('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        }

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            echo 'Server error(' . $method . ': ' . $path . '. Params: ' . json_encode($params) . '): ' . json_encode($responseBody) . "\n";
        }

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param  array<mixed>  $data
     * @param  string  $prefix
     * @return array<mixed>
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
