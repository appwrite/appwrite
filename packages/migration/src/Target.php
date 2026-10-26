<?php

namespace Utopia\Migration;

use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Client\Destinations;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Request\Factory as RequestFactory;

abstract class Target
{
    private const string PROTOCOLS = 'http,https';

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

    private ?Client $client = null;

    protected bool $followRedirects = false;

    /**
     * @var (\Closure(string): array<string>)|null
     */
    protected ?\Closure $resolver = null;

    private ?Destinations $destinations = null;

    protected string $rootResourceId = '';

    protected string $rootResourceType = '';

    abstract public static function getName(): string;

    abstract public static function getSupportedResources(): array;

    public function registerCache(Cache &$cache): void
    {
        $this->cache = &$cache;
    }

    public function setFollowRedirects(bool $followRedirects): static
    {
        $this->followRedirects = $followRedirects;
        $this->client = null;

        return $this;
    }

    /**
     * The resolver receives each request URL and returns the CURLOPT_RESOLVE
     * entries ("host:port:address[,address]") the connection must use. It
     * throws to refuse the URL. While a resolver is set, requests never go
     * through a proxy, which would resolve the host itself.
     *
     * @param  (\Closure(string): array<string>)|null  $resolver
     */
    public function setResolver(?\Closure $resolver): static
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * Where this target's requests may connect. Each connection and redirect hop is
     * checked against the address it actually reached, so DNS cannot answer differently
     * between a check and the connection. Unset, requests may connect anywhere.
     */
    public function setDestinations(Destinations $destinations): static
    {
        $this->destinations = $destinations;
        $this->client = null;

        return $this;
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
        $contentType = $headers['Content-Type'] ?? '';
        unset($headers['Content-Type']);

        // An empty value has always meant "do not send this header".
        $headers = \array_filter($headers, fn (string $value) => $value !== '');
        $headers['User-Agent'] = \php_uname('s').'-'.\php_uname('r').':php-'.\phpversion();

        $uri = \str_contains($path, 'http') ? $path : $this->endpoint.$path;
        $requests = new RequestFactory();

        $request = match (true) {
            $method === 'GET' || $method === 'HEAD' => $requests->query($method, $uri, $params, $headers),
            $contentType === ContentType::JSON => $requests->json($method, $uri, $params, $headers),
            default => $requests->form($method, $uri, $params, $headers),
        };

        $client = $this->resolver === null
            ? $this->client()
            : $this->client(($this->resolver)((string) $request->getUri()));

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new \Exception($error->getMessage(), Exception::CODE_INTERNAL, $error);
        }

        foreach (\array_keys($response->getHeaders()) as $name) {
            $responseHeaders[\strtolower($name)] = $response->getHeaderLine($name);
        }

        $responseBody = (string) $response->getBody();
        $responseStatus = $response->getStatusCode();

        if (\str_starts_with(\strtolower($response->getHeaderLine('Content-Type')), ContentType::JSON)) {
            $responseBody = \json_decode($responseBody, true);
        }

        if (! $this->followRedirects && $responseStatus >= 300 && $responseStatus < 400) {
            throw new \Exception($responseStatus.': Redirects are not followed', $responseStatus);
        }

        if ($responseStatus >= 400) {
            if (\is_array($responseBody)) {
                throw new \Exception(\json_encode($responseBody), $responseStatus);
            }

            throw new \Exception($responseStatus.': '.$responseBody, $responseStatus);
        }

        return $responseBody;
    }

    /**
     * The HTTP client for {@see call()}, built once per target so connections are
     * reused. A request pinned to resolved addresses gets a client of its own.
     *
     * @param  array<string>|null  $resolve
     */
    protected function client(?array $resolve = null): Client
    {
        if ($resolve === null) {
            return $this->client ??= $this->createClient([]);
        }

        return $this->createClient([
            \CURLOPT_RESOLVE => $resolve,
            \CURLOPT_PROXY => '',
        ]);
    }

    /**
     * @param  array<int, mixed>  $options
     */
    private function createClient(array $options): Client
    {
        return (new Client(new CurlAdapter(options: $options + [
            \CURLOPT_TIMEOUT_MS => 0,
            \CURLOPT_LOW_SPEED_LIMIT => 1,
            \CURLOPT_LOW_SPEED_TIME => static::STALL_TIMEOUT,
            \CURLOPT_PROTOCOLS_STR => self::PROTOCOLS,
            \CURLOPT_REDIR_PROTOCOLS_STR => self::PROTOCOLS,
        ]), $this->destinations))
            ->withFollowRedirects($this->followRedirects)
            ->withConnectTimeout(static::CONNECT_TIMEOUT);
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
     * Success callback
     */
    public function success(): void
    {
    }

    /**
     * Error callback
     */
    public function error(): void
    {
    }

    /**
     * Clean up callback
     */
    public function cleanUp(): void
    {
    }
}
