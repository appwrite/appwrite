<?php

namespace Executor;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Utopia\Fetch\BodyMultipart;
use Exception;
use Utopia\System\System;

class Executor
{
    // 0.8.6 is last version with object-based headers
    public const RESPONSE_FORMAT_OBJECT_HEADERS = '0.10.0';

    // 0.9.0 is first version with array-based headers
    public const RESPONSE_FORMAT_ARRAY_HEADERS = '0.11.0';

    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';
    public const METHOD_CONNECT = 'CONNECT';
    public const METHOD_TRACE = 'TRACE';

    protected bool $selfSigned = false;

    protected string $endpoint;
    protected array $headers;

    public function __construct()
    {
        $this->endpoint = System::getEnv('_APP_EXECUTOR_HOST', '');
        $this->headers = [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . System::getEnv('_APP_EXECUTOR_SECRET', ''),
            'x-opr-addressing-method' => 'anycast-efficient',
            'x-edge-bypass-gateway' => '1'
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
     * @param string $destination
     * @param array $variables
     * @param string $command
     */
    public function createRuntime(
        string $deploymentId,
        string $projectId,
        string $source,
        string $image,
        string $version,
        float $cpus,
        int $memory,
        int $timeout,
        bool $remove = false,
        string $entrypoint = '',
        string $destination = '',
        array $variables = [],
        string $command = null,
        string $outputDirectory = '',
        string $runtimeEntrypoint = ''
    ) {
        $runtimeId = "$projectId-$deploymentId-build";
        $route = "/runtimes";

        // Remove after migration
        if ($version === 'v3' || $version === 'v4') {
            $version = 'v5';
        }

        $params = [
            'runtimeId' => $runtimeId,
            'source' => $source,
            'destination' => $destination,
            'image' => $image,
            'entrypoint' => $entrypoint,
            'variables' => $variables,
            'remove' => $remove,
            'command' => $command,
            'cpus' => $cpus,
            'memory' => $memory,
            'version' => $version,
            'timeout' => $timeout,
            'outputDirectory' => $outputDirectory,
            'runtimeEntrypoint' => $runtimeEntrypoint
        ];


        $response = $this->call($this->endpoint, self::METHOD_POST, $route, [ 'x-opr-runtime-id' => $runtimeId ], $params, true, $timeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        return $response['body'];
    }

    /**
     * Listen to realtime logs stream of a runtime
     *
     * @param string $deploymentId
     * @param string $projectId
     * @param callable $callback
     */
    public function getLogs(
        string $deploymentId,
        string $projectId,
        string $timeout,
        callable $callback
    ) {
        $runtimeId = "$projectId-$deploymentId-build";
        $route = "/runtimes/{$runtimeId}/logs";
        $params = [
            'timeout' => $timeout
        ];

        $this->call($this->endpoint, self::METHOD_GET, $route, [ 'x-opr-runtime-id' => $runtimeId ], $params, true, $timeout, $callback);
    }

    /**
     * Delete Runtime
     *
     * Deletes a runtime and cleans up any containers remaining.
     *
     * @param string $projectId
     * @param string $deploymentId
     */
    public function deleteRuntime(string $projectId, string $deploymentId, string $suffix = '')
    {
        $runtimeId = "$projectId-$deploymentId" . $suffix;
        $route = "/runtimes/$runtimeId";

        $response = $this->call($this->endpoint, self::METHOD_DELETE, $route, [
            'x-opr-addressing-method' => 'broadcast'
        ], [], true, 30);

        // Temporary fix for race condition
        if ($response['headers']['status-code'] === 500 && \str_contains($response['body']['message'], 'already in progress')) {
            return true; // OK, removal already in progress
        }

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
     * @param string $body
     * @param array $variables
     * @param int $timeout
     * @param string $image
     * @param string $source
     * @param string $entrypoint
     * @param string $runtimeEntrypoint
     * @param bool $logging
     * @param string $responseFormat
     *
     * @return array
     */
    public function createExecution(
        string $projectId,
        string $deploymentId,
        ?string $body,
        array $variables,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        string $version,
        string $path,
        string $method,
        array $headers,
        float $cpus,
        int $memory,
        bool $logging,
        string $runtimeEntrypoint = '',
        ?int $requestTimeout = null,
        string $responseFormat = self::RESPONSE_FORMAT_OBJECT_HEADERS
    ) {
        $runtimeId = "$projectId-$deploymentId";
        $route = '/runtimes/' . $runtimeId . '/executions';

        // Remove after migration
        if ($version === 'v3' || $version === 'v4') {
            $version = 'v5';
        }

        $params = [
            'runtimeId' => $runtimeId,
            'variables' => $variables,
            'timeout' => $timeout,
            'path' => $path,
            'method' => $method,
            'headers' => $headers,
            'image' => $image,
            'source' => $source,
            'entrypoint' => $entrypoint,
            'cpus' => $cpus,
            'memory' => $memory,
            'version' => $version,
            'runtimeEntrypoint' => $runtimeEntrypoint,
            'logging' => $logging,
            'restartPolicy' => 'always' // Once utopia/orchestration has it, use DockerAPI::ALWAYS (0.13+)
        ];

        if (!empty($body)) {
            $params['body'] = $body;
        }

        // Safety timeout. Executor has timeout, and open runtime has soft timeout.
        // This one shouldn't really happen, but prevents from unexpected networking behaviours.
        if ($requestTimeout == null) {
            $requestTimeout = $timeout + 15;
        }

        $response = $this->call($this->endpoint, self::METHOD_POST, $route, [ 'x-opr-runtime-id' => $runtimeId, 'content-type' => 'multipart/form-data', 'accept' => 'multipart/form-data', 'x-executor-response-format' => $responseFormat ], $params, true, $requestTimeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        $headers = $response['body']['headers'] ?? [];
        if (is_string($headers)) {
            $headers = \json_decode($headers, true);
        }
        $response['body']['headers'] = $headers;
        $response['body']['statusCode'] = \intval($response['body']['statusCode'] ?? 500);
        $response['body']['duration'] = \floatval($response['body']['duration'] ?? 0);
        $response['body']['startTime'] = \floatval($response['body']['startTime'] ?? \microtime(true));

        return $response['body'];
    }

    public function createCommand(
        string $deploymentId,
        string $projectId,
        string $command,
        int $timeout
    ) {
        $runtimeId = "$projectId-$deploymentId-build";
        $route = "/runtimes/$runtimeId/commands";

        $params = [
            'command' => $command,
            'timeout' => $timeout
        ];

        $response = $this->call($this->endpoint, self::METHOD_POST, $route, [ 'x-opr-runtime-id' => $runtimeId ], $params, true, $timeout);

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
    private function call(string $endpoint, string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15, callable $callback = null)
    {
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init($endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders    = [];
        $responseStatus     = -1;
        $responseType       = '';
        $responseBody       = '';

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $multipart = new BodyMultipart();
                foreach ($params as $key => $value) {
                    $multipart->setPart($key, $value);
                }

                $headers['content-type'] = $multipart->exportHeader();
                $query = $multipart->exportBody();
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        if (isset($callback)) {
            $headers[] = 'accept: text/event-stream';

            $handleEvent = function ($ch, $data) use ($callback) {
                $callback($data);
                return \strlen($data);
            };

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, $handleEvent);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
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

        if (isset($callback)) {
            curl_close($ch);
            return [];
        }

        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        $curlErrorMessage = curl_error($ch);

        if ($decode) {
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
                    $json = json_decode($responseBody, true);

                    if ($json === null) {
                        throw new Exception('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        }

        if ($curlError) {
            if ($curlError == CURLE_OPERATION_TIMEDOUT) {
                throw new AppwriteException(AppwriteException::FUNCTION_SYNCHRONOUS_TIMEOUT);
            }
            throw new Exception($curlErrorMessage . ' with status code ' . $responseStatus, $responseStatus);
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
