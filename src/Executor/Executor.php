<?php

namespace Executor;

use Exception;
use Utopia\App;
use Utopia\CLI\Console;

class Executor
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

    private bool $selfSigned = false;

    private string $endpoint;

    protected array $headers;

    protected int $cpus;

    protected int $memory;

    public function __construct(string $endpoint)
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new Exception('Unsupported endpoint');
        }

        $this->endpoint = $endpoint;
        $this->cpus = \intval(App::getEnv('_APP_FUNCTIONS_CPUS', '1'));
        $this->memory = intval(App::getEnv('_APP_FUNCTIONS_MEMORY', '512'));
        $this->headers = [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . App::getEnv('_APP_EXECUTOR_SECRET', ''),
        ];
    }

    /**
     * Create runtime
     *
     * Launches a runtime container for a deployment ready for execution
     *
     * @param string $deploymentId
     * @param string $projectId
     * @param string $source
     * @param string $image
     * @param bool $remove
     * @param string $entrypoint
     * @param string $workdir
     * @param string $destination
     * @param array $variables
     * @param array $commands
     */
    public function createRuntime(
        string $deploymentId,
        string $projectId,
        string $source,
        string $image,
        string $version,
        bool $remove = false,
        string $entrypoint = '',
        string $workdir = '',
        string $destination = '',
        array $variables = [],
        array $commands = []
    ) {
        $runtimeId = "$projectId-$deploymentId";
        $route = "/runtimes";
        $params = [
            'runtimeId' => $runtimeId,
            'source' => $source,
            'destination' => $destination,
            'image' => $image,
            'entrypoint' => $entrypoint,
            'workdir' => $workdir,
            'variables' => $variables,
            'remove' => $remove,
            'commands' => $commands,
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'version' => $version,
        ];

        $timeout  = (int) App::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900);

        $response = $this->call(self::METHOD_POST, $route, [], $params, true, $timeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        return $response['body'];
    }

    /**
     * Create an execution
     *
     * @param string $projectId
     * @param string $deploymentId
     * @param string $payload
     * @param array $variables
     * @param int $timeout
     * @param string $image
     * @param string $source
     * @param string $entrypoint
     *
     * @return array
     */
    public function createExecution(
        string $projectId,
        string $deploymentId,
        string $body,
        array $variables,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        string $version,
        string $path,
        string $method,
        array $headers,
    ) {
        $headers['host'] = App::getEnv('_APP_DOMAIN', '');

        $runtimeId = "$projectId-$deploymentId";
        $route = '/runtimes/' . $runtimeId . '/execution';
        $params = [
            'runtimeId' => $runtimeId,
            'variables' => $variables,
            'body' => $body,
            'timeout' => $timeout,
            'path' => $path,
            'method' => $method,
            'headers' => $headers,

            'image' => $image,
            'source' => $source,
            'entrypoint' => $entrypoint,
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'version' => $version,
        ];

        $timeout  = (int) App::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900);

        $response = $this->call(self::METHOD_POST, $route, [], $params, true, $timeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        return $response['body'];
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
     * @param bool $decode
     * @return array|string
     * @throws Exception
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15)
    {
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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

        if ($decode) {
            switch (substr($responseType, 0, strpos($responseType, ';'))) {
                case 'application/json':
                    $json = json_decode($responseBody, true);

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
