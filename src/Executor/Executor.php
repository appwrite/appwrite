<?php

namespace Executor;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Utopia\Fetch\BodyMultipart;
use Exception;
use Utopia\System\System;

class Executor
{
    private const HTTP_METHODS = [
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 
        'HEAD', 'OPTIONS', 'CONNECT', 'TRACE'
    ];
    
    private const DEFAULT_HEADERS = [
        'content-type' => 'application/json',
        'x-opr-addressing-method' => 'anycast-efficient'
    ];

    private bool $selfSigned = false;
    private string $endpoint;
    private array $headers;

    public function __construct(string $endpoint)
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new Exception('Unsupported endpoint');
        }

        $this->endpoint = $endpoint;
        $this->headers = array_merge(self::DEFAULT_HEADERS, [
            'authorization' => 'Bearer ' . System::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);
    }

    public function createRuntime(
        string $deploymentId,
        string $projectId,
        string $source,
        string $image,
        string $version,
        float $cpus,
        int $memory,
        bool $remove = false,
        string $entrypoint = '',
        string $destination = '',
        array $variables = [],
        ?string $command = null,
    ): array {
        $runtimeId = "{$projectId}-{$deploymentId}-build";
        $version = $version === 'v3' ? 'v4' : $version; // Migration handling
        
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
            'timeout' => (int) System::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900),
        ];

        return $this->handleApiCall(
            'POST',
            '/runtimes',
            ['x-opr-runtime-id' => $runtimeId],
            $params,
            $params['timeout']
        );
    }

    public function getLogs(
        string $deploymentId,
        string $projectId,
        callable $callback
    ): void {
        $timeout = (int) System::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900);
        $runtimeId = "{$projectId}-{$deploymentId}-build";
        
        $this->call(
            'GET',
            "/runtimes/{$runtimeId}/logs",
            ['x-opr-runtime-id' => $runtimeId],
            ['timeout' => $timeout],
            true,
            $timeout,
            $callback
        );
    }

    public function deleteRuntime(string $projectId, string $deploymentId): array
    {
        $runtimeId = "{$projectId}-{$deploymentId}";
        
        return $this->handleApiCall(
            'DELETE',
            "/runtimes/{$runtimeId}",
            ['x-opr-addressing-method' => 'broadcast'],
            [],
            30
        );
    }

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
        ?string $runtimeEntrypoint = null,
        bool $logging = false,
        ?int $requestTimeout = null
    ): array {
        $runtimeId = "{$projectId}-{$deploymentId}";
        $version = $version === 'v3' ? 'v4' : $version; // Migration handling
        
        $headers['host'] ??= System::getEnv('_APP_DOMAIN', '');
        
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
            'restartPolicy' => 'always'
        ];

        if (!empty($body)) {
            $params['body'] = $body;
        }

        $response = $this->handleApiCall(
            'POST',
            "/runtimes/{$runtimeId}/executions",
            [
                'x-opr-runtime-id' => $runtimeId,
                'content-type' => 'multipart/form-data',
                'accept' => 'multipart/form-data'
            ],
            $params,
            $requestTimeout ?? $timeout + 15
        );

        // Process response
        $response['headers'] = \json_decode($response['headers'] ?? '{}', true);
        $response['statusCode'] = (int) ($response['statusCode'] ?? 500);
        $response['duration'] = (float) ($response['duration'] ?? 0);
        $response['startTime'] = (float) ($response['startTime'] ?? \microtime(true));

        return $response;
    }

    private function handleApiCall(
        string $method,
        string $route,
        array $headers,
        array $params,
        int $timeout
    ): array {
        $response = $this->call($method, $route, $headers, $params, true, $timeout);
        
        if ($response['headers']['status-code'] >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new Exception($message, $response['headers']['status-code']);
        }

        return $response['body'];
    }

    public function call(
        string $method,
        string $path = '',
        array $headers = [],
        array $params = [],
        bool $decode = true,
        int $timeout = 15,
        ?callable $callback = null
    ): array {
        $headers = array_merge($this->headers, $headers);
        $url = $this->buildUrl($method, $path, $params);
        
        $ch = $this->initializeCurl($url, $method, $headers, $params, $timeout, $callback);
        
        $responseHeaders = [];
        $responseBody = $this->executeCurl($ch, $responseHeaders, $callback);
        
        if (isset($callback)) {
            curl_close($ch);
            return [];
        }

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $this->handleCurlErrors($ch, $responseStatus);
        
        if ($decode) {
            $responseBody = $this->decodeResponse($responseBody, $responseType, $responseHeaders);
        }

        curl_close($ch);
        
        return [
            'headers' => $responseHeaders + ['status-code' => $responseStatus],
            'body' => $responseBody
        ];
    }

    private function buildUrl(string $method, string $path, array $params): string
    {
        $query = ($method === 'GET' && !empty($params)) ? '?' . http_build_query($params) : '';
        return $this->endpoint . $path . $query;
    }

    private function initializeCurl($url, $method, $headers, $params, $timeout, $callback): \CurlHandle
    {
        $ch = curl_init($url);
        $formattedHeaders = $this->formatHeaders($headers);
        
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_CONNECTTIMEOUT => 0,
            CURLOPT_TIMEOUT => $timeout,
        ];

        if (isset($callback)) {
            $curlOptions[CURLOPT_WRITEFUNCTION] = fn($ch, $data) => $this->handleStreamedResponse($callback, $data);
        } else {
            $curlOptions[CURLOPT_RETURNTRANSFER] = true;
        }

        if ($method !== 'GET') {
            $curlOptions[CURLOPT_POSTFIELDS] = $this->formatRequestBody($headers['content-type'] ?? '', $params);
        }

        if ($this->selfSigned) {
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = false;
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
        }

        curl_setopt_array($ch, $curlOptions);
        
        return $ch;
    }

    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }

    private function formatRequestBody(string $contentType, array $params): string|array
    {
        if (str_contains($contentType, 'application/json')) {
            return json_encode($params);
        }
        
        if (str_contains($contentType, 'multipart/form-data')) {
            $multipart = new BodyMultipart();
            foreach ($params as $key => $value) {
                $multipart->setPart($key, $value);
            }
            return $multipart->exportBody();
        }
        
        return http_build_query($params);
    }

    private function handleStreamedResponse(callable $callback, string $data): int
    {
        $callback($data);
        return strlen($data);
    }

    private function executeCurl($ch, array &$responseHeaders, ?callable $callback): string|bool
    {
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            
            if (count($header) >= 2) {
                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
            }
            
            return $len;
        });

        return curl_exec($ch);
    }

    private function handleCurlErrors($ch, int $responseStatus): void
    {
        $errno = curl_errno($ch);
        if ($errno) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new AppwriteException(AppwriteException::FUNCTION_SYNCHRONOUS_TIMEOUT);
            }
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }
    }

    private function decodeResponse(string|bool $body, string $contentType, array $headers): mixed
    {
        $contentType = explode(';', $contentType)[0];
        
        if ($contentType === 'multipart/form-data') {
            $boundary = explode('boundary=', $headers['content-type'] ?? '')[1] ?? '';
            $multipart = new BodyMultipart($boundary);
            $multipart->load(is_bool($body) ? '' : $body);
            return $multipart->getParts();
        }
        
        if ($contentType === 'application/json') {
            $decoded = json_decode($body, true);
            if ($decoded === null) {
                throw new Exception('Failed to parse response: ' . $body);
            }
            return $decoded;
        }
        
        return $body;
    }

    public function parseCookie(string $cookie): array
    {
        return parse_str(strtr($cookie, [
            '&' => '%26',
            '+' => '%2B',
            ';' => '&'
        ]));
    }

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