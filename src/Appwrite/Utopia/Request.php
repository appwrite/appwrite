<?php

namespace Appwrite\Utopia;

use Appwrite\SDK\Method;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request\Filter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Route;
use Utopia\Psr7\ServerRequest;
use Utopia\Psr7\Stream;
use Utopia\Psr7\UploadedFile;
use Utopia\Psr7\Uri;
use Utopia\System\System;
use WeakMap;

class Request extends ServerRequest
{
    public const string METHOD_OPTIONS = 'OPTIONS';
    public const string METHOD_GET = 'GET';
    public const string METHOD_HEAD = 'HEAD';
    public const string METHOD_POST = 'POST';
    public const string METHOD_PATCH = 'PATCH';
    public const string METHOD_PUT = 'PUT';
    public const string METHOD_DELETE = 'DELETE';
    public const string METHOD_TRACE = 'TRACE';
    public const string METHOD_CONNECT = 'CONNECT';

    /**
     * @var WeakMap<ServerRequestInterface, array<string, mixed>>|null
     */
    private static ?WeakMap $state = null;

    /**
     * @var array<Filter>
     */
    private array $filters = [];
    private ?Route $route = null;
    private ?array $filteredParams = null;
    private ?Authorization $authorization = null;
    private ?User $user = null;
    private SwooleRequest $swoole;

    public static function param(ServerRequestInterface $request, string $key, mixed $default = null): mixed
    {
        return self::params($request)[$key] ?? $default;
    }

    public static function params(ServerRequestInterface $request): array
    {
        $filtered = self::getState($request, 'filteredParams');
        if (\is_array($filtered)) {
            return $filtered;
        }

        $parameters = self::rawRequestParams($request);
        $filters = self::filters($request);
        $route = self::route($request);

        if ($filters === [] || $route === null) {
            return $parameters;
        }

        $methods = $route->getLabel('sdk', null);
        if (empty($methods)) {
            return $parameters;
        }

        if (!\is_array($methods)) {
            $id = $methods->getNamespace() . '.' . $methods->getMethodName();
        } else {
            $matched = null;
            foreach ($methods as $method) {
                /** @var Method|null $method */
                if ($method === null) {
                    continue;
                }

                $methodParamNames = \array_map(fn ($param) => $param->getName(), $method->getParameters());
                $invalidParams = \array_diff(\array_keys($parameters), $methodParamNames);

                if (empty($methodParamNames) || empty($invalidParams)) {
                    $matched = $method;
                    break;
                }
            }

            $id = $matched !== null
                ? $matched->getNamespace() . '.' . $matched->getMethodName()
                : 'unknown.unknown';
        }

        try {
            foreach ($filters as $filter) {
                $parameters = $filter->parse($parameters, $id);
            }
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if (\is_int($code) && $code >= 400 && $code < 500) {
                self::setState($request, 'filteredParams', $parameters);
            }
            throw $e;
        }

        self::setState($request, 'filteredParams', $parameters);
        return $parameters;
    }

    public static function query(ServerRequestInterface $request, string $key, mixed $default = null): mixed
    {
        return $request->getQueryParams()[$key] ?? $default;
    }

    public static function payload(ServerRequestInterface $request, string $key, mixed $default = null): mixed
    {
        $payload = $request->getParsedBody();
        if (\is_object($payload)) {
            $payload = get_object_vars($payload);
        }

        return \is_array($payload) ? ($payload[$key] ?? $default) : $default;
    }

    public static function rawPayload(ServerRequestInterface $request): string
    {
        return (string) $request->getBody();
    }

    public static function ip(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $remoteAddr = $server['remote_addr'] ?? '0.0.0.0';
        $trustedHeaders = \array_filter(\array_map(
            trim(...),
            \array_map(strtolower(...), \explode(',', System::getEnv('_APP_TRUSTED_HEADERS', 'x-forwarded-for')))
        ));

        foreach ($trustedHeaders as $header) {
            $headerValue = $request->getHeaderLine($header);
            if ($headerValue === '') {
                continue;
            }

            $ip = trim(explode(',', $headerValue)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return (string) $remoteAddr;
    }

    public static function protocol(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $protocol = $request->getHeaderLine('x-forwarded-proto') ?: (string) ($server['server_protocol'] ?? 'https');

        if ($protocol === 'HTTP/1.1') {
            return 'http';
        }

        return match ($protocol) {
            'http', 'https', 'ws', 'wss' => $protocol,
            default => 'https',
        };
    }

    public static function port(ServerRequestInterface $request): string
    {
        $forwardedHost = $request->getHeaderLine('x-forwarded-host') ?: $request->getHeaderLine('host');

        return $request->getHeaderLine('x-forwarded-port') ?: (string) parse_url(self::protocol($request) . '://' . $forwardedHost, PHP_URL_PORT);
    }

    public static function hostname(ServerRequestInterface $request): string
    {
        $forwardedHost = $request->getHeaderLine('x-forwarded-host') ?: $request->getHeaderLine('host');
        $hostname = parse_url(self::protocol($request) . '://' . $forwardedHost, PHP_URL_HOST);
        return strtolower(\strval($hostname));
    }

    public static function referer(ServerRequestInterface $request, string $default = ''): string
    {
        return $request->getHeaderLine('referer') ?: $default;
    }

    public static function origin(ServerRequestInterface $request, string $default = ''): string
    {
        return $request->getHeaderLine('origin') ?: $default;
    }

    public static function userAgent(ServerRequestInterface $request, string $default = ''): string
    {
        $forwardedUserAgent = $request->getHeaderLine('x-forwarded-user-agent');
        $authorization = self::getState($request, 'authorization');
        $user = self::getState($request, 'user');

        if ($forwardedUserAgent !== '' && $authorization instanceof Authorization && $user instanceof User && $user->isKey($authorization->getRoles())) {
            return $forwardedUserAgent;
        }

        return $request->getHeaderLine('user-agent') ?: $default;
    }

    public static function files(ServerRequestInterface $request, string|int $key): array
    {
        $files = self::denormalizeFiles($request->getUploadedFiles());

        return $files[strtolower((string) $key)] ?? $files[$key] ?? [];
    }

    public static function size(ServerRequestInterface $request): int
    {
        $headerSize = 0;
        foreach ($request->getHeaders() as $name => $values) {
            $headerSize += mb_strlen($name . ': ' . implode(', ', $values), '8bit');
        }

        return $headerSize + mb_strlen((string) $request->getBody(), '8bit');
    }

    public static function contentRangeStart(ServerRequestInterface $request): ?int
    {
        return self::parseContentRangeHeader($request)['start'] ?? null;
    }

    public static function contentRangeEnd(ServerRequestInterface $request): ?int
    {
        return self::parseContentRangeHeader($request)['end'] ?? null;
    }

    public static function contentRangeSize(ServerRequestInterface $request): ?int
    {
        return self::parseContentRangeHeader($request)['size'] ?? null;
    }

    public static function rangeStart(ServerRequestInterface $request): ?int
    {
        return self::parseRangeHeader($request)['start'] ?? null;
    }

    public static function rangeEnd(ServerRequestInterface $request): ?int
    {
        return self::parseRangeHeader($request)['end'] ?? null;
    }

    public static function rangeUnit(ServerRequestInterface $request): ?string
    {
        return self::parseRangeHeader($request)['unit'] ?? null;
    }

    public static function rememberRoute(ServerRequestInterface $request, ?Route $route): void
    {
        self::setState($request, 'route', $route);
        self::setState($request, 'filteredParams', null);
    }

    public static function route(ServerRequestInterface $request): ?Route
    {
        $route = self::getState($request, 'route');
        return $route instanceof Route ? $route : null;
    }

    public static function addRequestFilter(ServerRequestInterface $request, Filter $filter): void
    {
        $filters = self::filters($request);
        $filters[] = $filter;
        self::setState($request, 'filters', $filters);
        self::setState($request, 'filteredParams', null);
    }

    /**
     * @return array<Filter>
     */
    public static function filters(ServerRequestInterface $request): array
    {
        $filters = self::getState($request, 'filters');
        return \is_array($filters) ? $filters : [];
    }

    public static function rememberAuthorization(ServerRequestInterface $request, Authorization $authorization): void
    {
        self::setState($request, 'authorization', $authorization);
    }

    public static function rememberUser(ServerRequestInterface $request, User $user): void
    {
        self::setState($request, 'user', $user);
    }

    public static function cacheKey(ServerRequestInterface $request): string
    {
        $params = self::params($request);
        $allowedParams = self::route($request)?->getLabel('cache.params', null);
        if ($allowedParams !== null) {
            $params = array_intersect_key($params, array_flip($allowedParams));
        }
        if (!isset($params['project'])) {
            $params['project'] = $request->getHeaderLine('x-appwrite-project') ?: '';
        }
        ksort($params);
        return md5($request->getRequestTarget() . '*' . serialize($params) . '*' . APP_CACHE_BUSTER);
    }

    public function __construct(SwooleRequest $request)
    {
        $this->swoole = $request;

        $rawBody = $request->rawContent() ?: '';
        $headers = $this->headersFromSwoole($request);
        $server = $request->server ?? [];
        $method = (string) ($server['request_method'] ?? 'UNKNOWN');

        parent::__construct(
            method: $method,
            uri: $this->uriFromSwoole($request, $headers),
            serverParams: $server,
            cookieParams: $request->cookie ?? [],
            queryParams: $request->get ?? [],
            uploadedFiles: UploadedFile::normalizeFiles($request->files ?? []),
            parsedBody: $this->parsedBodyFromSwoole($request, $method, $headers, $rawBody),
            body: new Stream($rawBody),
            headers: $headers,
        );
    }

    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->getParams()[$key] ?? $default;
    }

    public function getParams(): array
    {
        if ($this->filteredParams !== null) {
            return $this->filteredParams;
        }

        $parameters = $this->rawParams();

        if (!$this->hasFilters() || !$this->hasRoute()) {
            return $parameters;
        }

        $methods = $this->getRoute()?->getLabel('sdk', null);
        if (empty($methods)) {
            return $parameters;
        }

        if (!\is_array($methods)) {
            $id = $methods->getNamespace() . '.' . $methods->getMethodName();
        } else {
            $matched = null;
            foreach ($methods as $method) {
                /** @var Method|null $method */
                if ($method === null) {
                    continue;
                }

                $methodParamNames = \array_map(fn ($param) => $param->getName(), $method->getParameters());
                $invalidParams = \array_diff(\array_keys($parameters), $methodParamNames);

                if (empty($methodParamNames) || empty($invalidParams)) {
                    $matched = $method;
                    break;
                }
            }

            $id = $matched !== null
                ? $matched->getNamespace() . '.' . $matched->getMethodName()
                : 'unknown.unknown';
        }

        try {
            foreach ($this->getFilters() as $filter) {
                $parameters = $filter->parse($parameters, $id);
            }
        } catch (\Throwable $e) {
            $code = $e->getCode();
            if (\is_int($code) && $code >= 400 && $code < 500) {
                $this->filteredParams = $parameters;
            }
            throw $e;
        }

        $this->filteredParams = $parameters;
        return $parameters;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function getPayload(string $key, mixed $default = null): mixed
    {
        $payload = $this->parsedBody;
        if (\is_object($payload)) {
            $payload = get_object_vars($payload);
        }

        return \is_array($payload) ? ($payload[$key] ?? $default) : $default;
    }

    public function getRawPayload(): string
    {
        return (string) $this->body;
    }

    public function getHeaderLine(string $name, string $default = ''): string
    {
        $value = parent::getHeaderLine($name);
        return $value === '' ? $default : $value;
    }

    public function getCookie(string $key, string $default = ''): string
    {
        return $this->cookieParams[$key] ?? $default;
    }

    public function setCookieParams(array $cookies): static
    {
        $this->cookieParams = $cookies;
        return $this;
    }

    public function getIP(): string
    {
        $remoteAddr = $this->serverParams['remote_addr'] ?? '0.0.0.0';
        $trustedHeaders = \array_filter(\array_map(
            trim(...),
            \array_map(strtolower(...), \explode(',', System::getEnv('_APP_TRUSTED_HEADERS', 'x-forwarded-for')))
        ));

        foreach ($trustedHeaders as $header) {
            $headerValue = $this->getHeaderLine($header);
            if ($headerValue === '') {
                continue;
            }

            $ip = trim(explode(',', $headerValue)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return (string) $remoteAddr;
    }

    public function getProtocol(): string
    {
        $protocol = $this->getHeaderLine('x-forwarded-proto', (string) ($this->serverParams['server_protocol'] ?? 'https'));

        if ($protocol === 'HTTP/1.1') {
            return 'http';
        }

        return match ($protocol) {
            'http', 'https', 'ws', 'wss' => $protocol,
            default => 'https',
        };
    }

    public function getPort(): string
    {
        return $this->getHeaderLine('x-forwarded-port', (string) parse_url($this->getProtocol() . '://' . $this->getHeaderLine('x-forwarded-host', $this->getHeaderLine('host')), PHP_URL_PORT));
    }

    public function getHostname(): string
    {
        $hostname = parse_url($this->getProtocol() . '://' . $this->getHeaderLine('x-forwarded-host', $this->getHeaderLine('host')), PHP_URL_HOST);
        return strtolower(\strval($hostname));
    }

    public function setMethod(string $method): static
    {
        $this->method = strtoupper($method);
        $this->serverParams['request_method'] = $this->method;
        $this->filteredParams = null;
        return $this;
    }

    public function setURI(string $uri): static
    {
        $this->serverParams['request_uri'] = $uri;
        $this->uri = Uri::parse($uri);
        $this->filteredParams = null;
        return $this;
    }

    public function getFiles(string|int $key): array
    {
        return $this->swoole->files[strtolower((string) $key)] ?? $this->swoole->files[$key] ?? [];
    }

    public function getSize(): int
    {
        return self::size($this);
    }

    public function getReferer(string $default = ''): string
    {
        return $this->getHeaderLine('referer', $default);
    }

    public function getOrigin(string $default = ''): string
    {
        return $this->getHeaderLine('origin', $default);
    }

    public function getUserAgent(string $default = ''): string
    {
        $forwardedUserAgent = $this->getHeaderLine('x-forwarded-user-agent');
        if ($forwardedUserAgent !== '' && $this->authorization !== null && $this->user?->isKey($this->authorization->getRoles())) {
            return $forwardedUserAgent;
        }

        return $this->getHeaderLine('user-agent', $default);
    }

    public function getAccept(string $default = ''): string
    {
        return $this->getHeaderLine('accept', $default);
    }

    public function getHeaders(): array
    {
        $headers = parent::getHeaders();

        if ($this->cookieParams !== []) {
            $pairs = [];
            foreach ($this->cookieParams as $key => $value) {
                $pairs[] = "{$key}={$value}";
            }
            $headers['cookie'] = [\implode('; ', $pairs)];
        }

        return $headers;
    }

    public function setQueryString(array $params): static
    {
        $this->queryParams = $params;
        $this->filteredParams = null;
        return $this;
    }

    public function setPayload(array $params): static
    {
        $this->parsedBody = $params;
        $this->filteredParams = null;
        return $this;
    }

    public function setHeader(string $key, string $value): static
    {
        $clone = $this->withHeader($key, $value);
        $this->headers = $clone->getHeaders();
        $this->rebuildHeaderNames();
        return $this;
    }

    public function addHeader(string $key, string $value): static
    {
        $clone = $this->withAddedHeader($key, $value);
        $this->headers = $clone->getHeaders();
        $this->rebuildHeaderNames();
        return $this;
    }

    public function addFilter(Filter $filter): void
    {
        $this->filters[] = $filter;
        $this->filteredParams = null;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function resetFilters(): void
    {
        $this->filters = [];
        $this->filteredParams = null;
    }

    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }

    public function setRoute(?Route $route): void
    {
        $this->route = $route;
        $this->filteredParams = null;
    }

    public function getRoute(): ?Route
    {
        return $this->route;
    }

    public function hasRoute(): bool
    {
        return $this->route !== null;
    }

    public function getContentRangeStart(): ?int
    {
        return $this->parseContentRange()['start'] ?? null;
    }

    public function getContentRangeEnd(): ?int
    {
        return $this->parseContentRange()['end'] ?? null;
    }

    public function getContentRangeSize(): ?int
    {
        return $this->parseContentRange()['size'] ?? null;
    }

    public function getRangeStart(): ?int
    {
        return $this->parseRange()['start'] ?? null;
    }

    public function getRangeEnd(): ?int
    {
        return $this->parseRange()['end'] ?? null;
    }

    public function getRangeUnit(): ?string
    {
        return $this->parseRange()['unit'] ?? null;
    }

    public function cacheIdentifier(): string
    {
        $params = $this->getParams();
        $allowedParams = $this->getRoute()?->getLabel('cache.params', null);
        if ($allowedParams !== null) {
            $params = array_intersect_key($params, array_flip($allowedParams));
        }
        if (!isset($params['project'])) {
            $params['project'] = $this->getHeaderLine('x-appwrite-project', '');
        }
        ksort($params);
        return md5($this->getRequestTarget() . '*' . serialize($params) . '*' . APP_CACHE_BUSTER);
    }

    public function setAuthorization(Authorization $authorization): void
    {
        $this->authorization = $authorization;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    private function rawParams(): array
    {
        return match ($this->method) {
            self::METHOD_POST, self::METHOD_PUT, self::METHOD_PATCH, self::METHOD_DELETE => \is_array($this->parsedBody) ? $this->parsedBody : [],
            default => $this->queryParams,
        };
    }

    private function parseContentRange(): array
    {
        if (!preg_match('/^(\w+) (\d+)-(\d+)\/(\d+)$/', $this->getHeaderLine('content-range'), $matches)) {
            return [];
        }

        return ['unit' => $matches[1], 'start' => (int) $matches[2], 'end' => (int) $matches[3], 'size' => (int) $matches[4]];
    }

    private function parseRange(): array
    {
        if (!preg_match('/^(\w+)=(\d+)-(\d*)$/', $this->getHeaderLine('range'), $matches)) {
            return [];
        }

        return ['unit' => $matches[1], 'start' => (int) $matches[2], 'end' => $matches[3] === '' ? null : (int) $matches[3]];
    }

    private function rebuildHeaderNames(): void
    {
        $this->headerNames = [];
        foreach (\array_keys($this->headers) as $name) {
            $this->headerNames[strtolower((string) $name)] = (string) $name;
        }
    }

    private function uriFromSwoole(SwooleRequest $request, array $headers): UriInterface
    {
        $server = $request->server ?? [];
        $requestUri = (string) ($server['request_uri'] ?? '/');
        $query = (string) ($server['query_string'] ?? '');

        if ($query !== '' && !str_contains($requestUri, '?')) {
            $requestUri .= '?' . $query;
        }

        $host = $headers['host'] ?? '';
        $host = \is_array($host) ? ($host[0] ?? '') : $host;

        return $host === ''
            ? Uri::parse($requestUri)
            : Uri::parse($this->schemeFromHeaders($headers) . '://' . $host . $requestUri);
    }

    private function schemeFromHeaders(array $headers): string
    {
        $forwarded = $headers['x-forwarded-proto'] ?? null;
        $forwarded = \is_array($forwarded) ? ($forwarded[0] ?? null) : $forwarded;

        return \in_array($forwarded, ['http', 'https', 'ws', 'wss'], true) ? (string) $forwarded : 'http';
    }

    private function headersFromSwoole(SwooleRequest $request): array
    {
        $headers = [];

        foreach ($request->header ?? [] as $name => $value) {
            $headers[strtolower((string) $name)] = \is_array($value)
                ? array_values(array_map(strval(...), $value))
                : (string) $value;
        }

        return $headers;
    }

    private function parsedBodyFromSwoole(SwooleRequest $request, string $method, array $headers, string $rawBody): ?array
    {
        if (!\in_array(strtoupper($method), [self::METHOD_POST, self::METHOD_PUT, self::METHOD_PATCH, self::METHOD_DELETE], true)) {
            return null;
        }

        $contentType = $headers['content-type'] ?? '';
        $contentType = \is_array($contentType) ? ($contentType[0] ?? '') : $contentType;
        $contentType = trim(explode(';', (string) $contentType)[0]);

        if ($contentType === 'application/json') {
            $decoded = json_decode($rawBody, true);
            return \is_array($decoded) ? $decoded : [];
        }

        return $request->post ?? [];
    }

    private static function getState(ServerRequestInterface $request, string $key): mixed
    {
        self::$state ??= new WeakMap();
        return self::$state[$request][$key] ?? null;
    }

    private static function setState(ServerRequestInterface $request, string $key, mixed $value): void
    {
        self::$state ??= new WeakMap();
        $state = self::$state[$request] ?? [];
        $state[$key] = $value;
        self::$state[$request] = $state;
    }

    private static function rawRequestParams(ServerRequestInterface $request): array
    {
        if (\in_array($request->getMethod(), [self::METHOD_POST, self::METHOD_PUT, self::METHOD_PATCH, self::METHOD_DELETE], true)) {
            $body = $request->getParsedBody();

            if (\is_array($body)) {
                return $body;
            }

            if (\is_object($body)) {
                return get_object_vars($body);
            }

            return [];
        }

        return $request->getQueryParams();
    }

    private static function parseContentRangeHeader(ServerRequestInterface $request): array
    {
        if (!preg_match('/^(\w+) (\d+)-(\d+)\/(\d+)$/', $request->getHeaderLine('content-range'), $matches)) {
            return [];
        }

        return ['unit' => $matches[1], 'start' => (int) $matches[2], 'end' => (int) $matches[3], 'size' => (int) $matches[4]];
    }

    private static function parseRangeHeader(ServerRequestInterface $request): array
    {
        if (!preg_match('/^(\w+)=(\d+)-(\d*)$/', $request->getHeaderLine('range'), $matches)) {
            return [];
        }

        return ['unit' => $matches[1], 'start' => (int) $matches[2], 'end' => $matches[3] === '' ? null : (int) $matches[3]];
    }

    private static function denormalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                $stream = $file->getStream();
                $normalized[$key] = [
                    'name' => $file->getClientFilename(),
                    'type' => $file->getClientMediaType(),
                    'tmp_name' => (string) $stream->getMetadata('uri'),
                    'error' => $file->getError(),
                    'size' => $file->getSize(),
                ];
                continue;
            }

            if (\is_array($file)) {
                $normalized[$key] = self::denormalizeFiles($file);
            }
        }

        return $normalized;
    }
}
