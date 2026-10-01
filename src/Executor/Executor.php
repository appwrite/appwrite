<?php

namespace Executor;

use Appwrite\Utopia\Fetch\BodyMultipart;
use Executor\Exception as ExecutorException;
use Executor\Exception\Timeout as ExecutorTimeout;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Client\Destination\Anywhere;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Stream\Factory as StreamFactory;
use Utopia\System\System;

class Executor
{
    // 0.8.6 is last version with object-based headers
    public const RESPONSE_FORMAT_OBJECT_HEADERS = '0.10.0';

    // 0.9.0 is first version with array-based headers
    public const RESPONSE_FORMAT_ARRAY_HEADERS = '0.11.0';

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
    private function call(string $endpoint, string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15): array
    {
        $headers            = array_merge($this->headers, $headers);
        $url                = $endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : '');

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

        $request = (new RequestFactory())->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($method != self::METHOD_GET) {
            $request = $request->withBody((new StreamFactory())->createStream($query));
        }

        // No Accept-Encoding, so the executor never spends CPU compressing a response
        $client = (new Client(new CurlAdapter(options: [CURLOPT_ENCODING => null]), new Anywhere()))
            ->withFollowRedirects()
            ->withConnectTimeout(0)
            ->withTimeout($timeout);

        // Allow self signed certificates
        if ($this->selfSigned) {
            $client = $client->withSslVerification(false);
        }

        try {
            $response = $client->sendRequest($request);
        } catch (TimeoutException) {
            throw new ExecutorTimeout('Executor request timed out after ' . $timeout . ' seconds');
        } catch (ClientExceptionInterface $e) {
            throw new ExecutorException($e->getMessage() . ' with status code 0', 0);
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = \end($values);
        }

        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = $response->getStatusCode();
        $responseBody   = (string) $response->getBody();

        if ($decode) {
            $strpos = strpos($responseType, ';');
            $strpos = \is_bool($strpos) ? \strlen($responseType) : $strpos;
            switch (substr($responseType, 0, $strpos)) {
                case 'multipart/form-data':
                    $boundary = \explode('boundary=', $responseHeaders['content-type'])[1] ?? '';
                    $multipartResponse = new BodyMultipart($boundary);
                    $multipartResponse->load($responseBody);

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
