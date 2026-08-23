<?php

namespace Executor;

use Appwrite\Utopia\Fetch\BodyMultipart;
use Appwrite\Utopia\Fetch\BodyMultipartStream;
use Executor\Exception as ExecutorException;
use Executor\Exception\Timeout as ExecutorTimeout;
use Utopia\System\System;

class Executor
{
    // 0.8.6 is last version with object-based headers
    public const RESPONSE_FORMAT_OBJECT_HEADERS = '0.10.0';

    // 0.9.0 is first version with array-based headers
    public const RESPONSE_FORMAT_ARRAY_HEADERS = '0.11.0';

    // 0.12.0 is first version that flushes parts as they are produced
    public const RESPONSE_FORMAT_STREAM = '0.12.0';

    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_DELETE = 'DELETE';

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

        $status = $response['headers']['status-code'];
        $message = \is_string($response['body']) ? $response['body'] : ($response['body']['message'] ?? '');

        // Runtime already gone — nothing to do
        if ($status === 404) {
            return true;
        }

        // Temporary fix for race condition
        if ($status === 500 && \str_contains($message, 'already in progress')) {
            return true; // OK, removal already in progress
        }

        if ($status >= 400) {
            $type = \is_array($response['body']) ? ($response['body']['type'] ?? ExecutorException::GENERAL_UNKNOWN) : ExecutorException::GENERAL_UNKNOWN;
            throw new ExecutorException($message, $status, type: $type);
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
        string $responseFormat = self::RESPONSE_FORMAT_OBJECT_HEADERS,
        ?callable $onPart = null
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

        $streamed = false;
        $parts = [];
        $buffered = '';
        $reader = null;
        $onData = null;

        if ($onPart !== null) {
            $onData = function (string $data, array $responseHeaders) use (&$streamed, &$parts, &$buffered, &$reader, $onPart): void {
                if (!$streamed && $reader === null) {
                    $format = $responseHeaders['x-executor-response-format'] ?? '';

                    // The request asks for the streaming format, but an executor that predates it
                    // answers with a complete document and echoes nothing. Detection has to be the
                    // echo: curl hands over the same sized runs either way.
                    if ($format !== '' && \version_compare($format, self::RESPONSE_FORMAT_STREAM, '>=')) {
                        $boundary = \trim(\explode('boundary=', $responseHeaders['content-type'] ?? '')[1] ?? '', '"');
                        if ($boundary === '') {
                            $buffered .= $data;
                            return;
                        }

                        $streamed = true;
                        $reader = new BodyMultipartStream(
                            $boundary,
                            function (string $name, string $chunk, bool $isLast) use (&$parts, $onPart): void {
                                if ($name !== 'body') {
                                    $parts[$name] = ($parts[$name] ?? '') . $chunk;
                                }

                                $onPart($name, $chunk, $isLast);
                            }
                        );
                    }
                }

                if ($reader !== null) {
                    $reader->feed($data);

                    return;
                }

                $buffered .= $data;
            };
        }

        $response = $this->call($this->endpoint, self::METHOD_POST, $route, [ 'x-opr-runtime-id' => $runtimeId, 'content-type' => 'multipart/form-data', 'accept' => 'multipart/form-data', 'x-executor-response-format' => $responseFormat ], $params, true, $requestTimeout, $onData);

        if ($onPart !== null) {
            if (!$streamed) {
                $boundary = \trim(\explode('boundary=', $response['headers']['content-type'] ?? '')[1] ?? '', '"');
                $parts = (new BodyMultipart($boundary))->load($buffered)->getParts();
            }

            $response['body'] = $parts;
        }

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : ($response['body']['message'] ?? '');
            $type = \is_array($response['body']) ? ($response['body']['type'] ?? ExecutorException::GENERAL_UNKNOWN) : ExecutorException::GENERAL_UNKNOWN;
            throw new ExecutorException($message, $status, type: $type);
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
     * @return array
     * @throws Exception
     */
    private function call(string $endpoint, string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15, ?callable $callback = null): array
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
            $handleEvent = function ($ch, $data) use ($callback, &$responseHeaders) {
                // Headers are complete before the first body byte, so the callback can tell
                // what the other side agreed to before deciding what to do with the payload.
                $callback($data, $responseHeaders);
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

        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        $curlErrorMessage = curl_error($ch);

        // A callback consumed the body as it arrived, so there is nothing left to decode.
        if ($decode && !isset($callback)) {
            $strpos = strpos($responseType, ';');
            $strpos = \is_bool($strpos) ? \strlen($responseType) : $strpos;
            switch (substr($responseType, 0, $strpos)) {
                case 'multipart/form-data':
                    $boundary = \explode('boundary=', $responseHeaders['content-type'])[1] ?? '';
                    $multipartResponse = new BodyMultipart($boundary);
                    $multipartResponse->load(\is_bool($responseBody) ? '' : $responseBody);

                    $responseBody = $multipartResponse->getParts();
                    break;
                case 'application/json':
                    $json = json_decode($responseBody, true);

                    if ($json === null) {
                        throw new ExecutorException('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        }

        if ($curlError) {
            if ($curlError == CURLE_OPERATION_TIMEDOUT) {
                throw new ExecutorTimeout('Executor request timed out after ' . $timeout . ' seconds');
            }
            throw new ExecutorException($curlErrorMessage . ' with status code ' . $responseStatus, $responseStatus);
        }

        $responseHeaders['status-code'] = $responseStatus;

        return [
            'headers' => $responseHeaders,
            'body' => isset($callback) ? '' : $responseBody
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
