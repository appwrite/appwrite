<?php

namespace Utopia\Migration;

abstract class Target
{
    /**
     * Seconds to wait for a remote to accept the connection.
     */
    public const int CONNECT_TIMEOUT = 10;

    /**
     * Seconds a request may go without receiving a byte before it is abandoned.
     * Transfers of any length stay alive as long as data keeps arriving.
     */
    public const int STALL_TIMEOUT = 30;

    /**
     * Seconds an Appwrite SDK request may take in total. The SDK transport only
     * takes a total timeout; its requests are single API pages and file chunks.
     */
    public const int REQUEST_TIMEOUT = 30;

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected array $headers = [
        'Content-Type' => '',
    ];

    public Cache $cache;

    /**
     * Errors
     *
     * @var array<Exception>
     */
    public array $errors = [];

    /**
     * Warnings
     *
     * @var array<Warning>
     */
    public array $warnings = [];

    protected string $endpoint = '';

    protected string $rootResourceId = '';

    protected string $rootResourceType = '';

    abstract public static function getName(): string;

    abstract public static function getSupportedResources(): array;

    public function registerCache(Cache &$cache): void
    {
        $this->cache = &$cache;
    }

    /**
     * Run Transfer
     *
     * @param  array<string>  $resources  Resources to transfer
     * @param  callable  $callback  Callback to run after transfer
     * @param  string  $rootResourceId  Root resource ID, If enabled you can only transfer a single root resource
     */
    abstract public function run(array $resources, callable $callback, string $rootResourceId = ''): void;

    /**
     * Report Resources
     *
     * This function performs a count of all resources that are available for transfer.
     * It also serves a secondary purpose of checking if the API is available for the given adapter.
     *
     * On Destinations, this function should just return nothing but still check if the API is available.
     * If any issues are found then an exception should be thrown with an error message.
     *
     * @param  array<string>  $resources  Resources to report
     * @param  array<string, array<string>>  $resourceIds  Map of resource type to IDs. Only top-level resources supported.
     * @return array<string, int>
     *
     * @throws \Exception if resourceIds contains non-top-level resource types
     */
    abstract public function report(array $resources = [], array $resourceIds = []): array;

    /**
     * Make an API call
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $responseHeaders
     * @return array<mixed>|string
     *
     * @throws \Exception
     */
    protected function call(
        string $method,
        string $path = '',
        array $headers = [],
        array $params = [],
        array &$responseHeaders = []
    ): array|string {
        $headers = \array_merge($this->headers, $headers);
        $ch = \curl_init((
            \str_contains($path, 'http')
            ? $path.(($method == 'GET' && ! empty($params)) ? '?'.\http_build_query($params) : '')
            : $this->endpoint.$path.(
                ($method == 'GET' && ! empty($params))
                ? '?'.\http_build_query($params)
                : ''
            )
        ));

        $query = match ($headers['Content-Type']) {
            'application/json' => \json_encode($params),
            'multipart/form-data' => $this->flatten($params),
            default => \http_build_query($params),
        };

        foreach ($headers as $i => $header) {
            $headers[] = $i.':'.$header;
            unset($headers[$i]);
        }

        if ($method === 'HEAD') {
            \curl_setopt($ch, CURLOPT_NOBODY, true);
        } else {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_USERAGENT, php_uname('s').'-'.php_uname('r').':php-'.phpversion());
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, static::CONNECT_TIMEOUT);
        \curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        \curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, static::STALL_TIMEOUT);
        \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', strtolower($header), 2);

            if (\count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[\strtolower(\trim($header[0]))] = \trim($header[1]);

            return $len;
        });

        if ($method != 'GET') {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        $responseBody = curl_exec($ch);

        $responseType = $responseHeaders['Content-Type'] ?? $responseHeaders['content-type'] ?? '';
        $responseStatus = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch (\substr($responseType, 0, \strpos($responseType, ';'))) {
            case 'application/json':
                $responseBody = \json_decode($responseBody, true);
                break;
        }

        if (\curl_errno($ch)) {
            throw new \Exception(\curl_error($ch), Exception::CODE_INTERNAL);
        }


        if ($responseStatus >= 400) {
            if (\is_array($responseBody)) {
                throw new \Exception(\json_encode($responseBody), $responseStatus);
            } else {
                throw new \Exception($responseStatus.': '.$responseBody, $responseStatus);
            }
        }

        return $responseBody;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (\is_array($value)) {
                $output += $this->flatten($value, $finalKey);
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }

    /**
     * Validate that resourceIds only contains top-level resources
     */
    protected function validateResourceIds(array $resourceIds): void
    {
        foreach (array_keys($resourceIds) as $resourceType) {
            if (!in_array($resourceType, Transfer::ROOT_RESOURCES)) {
                throw new \Exception(
                    'Invalid resource type in resourceIds: ' . $resourceType . '. ' .
                    'Only top-level resources are supported: ' . implode(', ', Transfer::ROOT_RESOURCES)
                );
            }
        }
    }

    /**
     * Get Errors
     *
     * @returns array<Exception>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Add Error
     */
    public function addError(Exception $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Get Warnings
     *
     * @returns array<Warning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Add Warning
     */
    public function addWarning(Warning $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Completion callback
     */
    public function shutdown(): void
    {
    }

    /**
     * Commit the terminal state of the last run() that returned.
     *
     * run() may leave that state uncommitted — the Appwrite destination keeps the databases
     * it provisions in `provisioning` and defers its overwrite cleanup — so once run() has
     * returned, the caller persists whatever claim must precede those side effects and then
     * calls success(). Every step is attempted; if any fails, each failure is recorded with
     * addError() and success() throws Exception\Finalization carrying them, so a failed
     * finalization cannot pass for a completed one. Call error() instead for a run that will
     * not be finalized.
     *
     * @throws Exception\Finalization
     */
    public function success(): void
    {
    }

    /**
     * Called instead of success() when the run failed or will not be finalized.
     */
    public function error(): void
    {
    }

    /**
     * End the lifecycle. A target that defers work to success() records an error here for
     * each resource a returned run left unfinalized when neither success() nor error() was
     * called, so skipping success() is reported rather than passing silently.
     */
    public function cleanUp(): void
    {
    }
}
