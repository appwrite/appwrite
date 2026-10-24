<?php

namespace Appwrite\Utopia;

use Appwrite\SDK\Method;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request\Filter;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Adapter\Swoole\Request as UtopiaRequest;
use Utopia\Http\Route;
use Utopia\System\System;

class Request extends UtopiaRequest
{
    private const string UPLOAD_MARKER = 'swoole-upload-file';

    /**
     * @var array<Filter>
     */
    private array $filters = [];
    private ?Route $route = null;
    private ?array $filteredParams = null;

    public function __construct(SwooleRequest $request)
    {
        $trustedHeaders = System::getEnv('_APP_TRUSTED_HEADERS', 'x-forwarded-for');
        $this->setTrustedIpHeaders(explode(',', $trustedHeaders));

        parent::__construct($request);

        $this->dropSpoofedUploads();
    }

    /**
     * @inheritdoc
     */
    public function getParams(): array
    {
        if ($this->filteredParams !== null) {
            return $this->filteredParams;
        }

        // The framework resolves a callable argument by calling it, so a request value such as
        // ["Class", "method"] never reaches a route; only defaults the route registers are resolved.
        $parameters = \array_filter(
            parent::getParams(),
            fn (mixed $value): bool => \is_string($value) || !\is_callable($value),
        );

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

                // Find the method that matches the parameters passed
                $methodParamNames = \array_map(fn ($param) => $param->getName(), $method->getParameters());
                $invalidParams = \array_diff(\array_keys($parameters), $methodParamNames);

                // No params defined, or all params are valid
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
            /*
            * 4xx filter throws are user-input errors that the action layer
            * revalidates and reports. Cache the raw, pre-filter parameters
            * so a subsequent getParams() — e.g. when the framework builds
            * arguments for an error hook — returns without re-running
            * filters. Otherwise the second throw gets wrapped as
            * "Error handler had an error: ..." (HTTP 500), masking the
            * intended 400.
            */
            $code = $e->getCode();
            if (\is_int($code) && $code >= 400 && $code < 500) {
                $this->filteredParams = $parameters;
            }
            throw $e;
        }

        $this->filteredParams = $parameters;
        return $parameters;
    }

    /**
     * Function to add a response filter, the order of filters are first in - first out.
     *
     * @param Filter $filter the response filter to set
     *
     * @return void
     */
    public function addFilter(Filter $filter): void
    {
        $this->filters[] = $filter;
        $this->filteredParams = null;
    }

    /**
     * Return the currently set filter
     *
     * @return array<Filter>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Reset filters
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->filters = [];
        $this->filteredParams = null;
    }

    /**
     * Check if a filter has been set
     *
     * @return bool
     */
    public function hasFilters(): bool
    {
        return !empty($this->filters);
    }

    /**
     * Function to set a request route
     *
     * @param Route|null $route the request route to set
     *
     * @return void
     */
    public function setRoute(?Route $route): void
    {
        $this->route = $route;
        $this->filteredParams = null;
    }

    /**
     * Return the current route
     *
     * @return Route|null
     */
    public function getRoute(): ?Route
    {
        return $this->route;
    }

    /**
     * Check if a route has been set
     *
     * @return bool
     */
    public function hasRoute(): bool
    {
        return $this->route !== null;
    }

    /**
     * Get headers
     *
     * Method for getting all HTTP headers, including a synthesized `cookie`
     * header. Swoole parses the incoming Cookie header into its own cookie jar,
     * so it is absent from the raw header map; rebuild it here so consumers that
     * forward request headers (e.g. function/site executions) still receive it.
     *
     * @return array<string, array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->getHeadersWithoutCookies([]);
    }

    /**
     * Get all headers like getHeaders(), leaving the named cookies out of the synthesized `cookie` header.
     *
     * @param list<string> $names
     * @return array<string, array<int, string>>
     */
    public function getHeadersWithoutCookies(array $names): array
    {
        $headers = parent::getHeaders();
        unset($headers['cookie']);

        $cookies = \array_diff_key($this->getCookieParams(), \array_flip($names));
        if (!empty($cookies)) {
            $pairs = [];
            foreach ($cookies as $key => $value) {
                $pairs[] = "{$key}={$value}";
            }
            $headers['cookie'] = [\implode('; ', $pairs)];
        }

        return $headers;
    }

    /**
     * Get User Agent
     *
     * Method for getting User Agent. Preferring forwarded agent for privileged users; otherwise returns default.
     *
     * @param  string  $default
     * @return string
     */
    public function getUserAgent(string $default = ''): string
    {
        $forwardedUserAgent = $this->getHeaderLine('x-forwarded-user-agent');
        if (!empty($forwardedUserAgent)) {
            $roles = $this->authorization->getRoles();
            $isAppUser = $this->user?->isKey($roles) ?? false;

            if ($isAppUser) {
                return $forwardedUserAgent;
            }
        }

        return UtopiaRequest::getUserAgent($default);
    }

    /**
     * Creates a unique stable cache identifier for this GET request.
     * Stable-sorts query params, use `serialize` to ensure key&value are part of cache keys.
     *
     * @return string
     */
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
        return md5($this->getURI() . '*' . serialize($params) . '*' . APP_CACHE_BUSTER);
    }

    private ?Authorization $authorization = null;
    private ?User $user = null;

    /**
     * @var array<string>
     */
    private array $scopes = [];

    public function setAuthorization(Authorization $authorization): void
    {
        $this->authorization = $authorization;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Drop upload paths named by the client
     *
     * Swoole's multipart parser accepts a client-supplied `Swoole-Upload-File`
     * part header as a ready temp file (swoole/swoole-src#6309): the path is
     * reported as `tmp_name`, passes `is_uploaded_file()`, and is unlinked when
     * the request is freed. Such paths leave `tmpfiles` and are blanked in `files`.
     *
     * Temporary: remove this guard and the uploadMarkers() scan once a released
     * Swoole ships the upstream fix (swoole/swoole-src#6309) and we require it.
     */
    private function dropSpoofedUploads(): void
    {
        $tmpfiles = $this->swoole->tmpfiles;

        if (! \is_array($tmpfiles) || $tmpfiles === []) {
            return;
        }

        $markers = $this->uploadMarkers();
        $spoofed = \array_values(\array_filter($tmpfiles, fn (mixed $path): bool => \is_string($path) && \in_array(\trim($path), $markers, true)));

        if ($spoofed === []) {
            return;
        }

        $this->swoole->tmpfiles = \array_values(\array_filter($tmpfiles, fn (mixed $path): bool => ! \in_array($path, $spoofed, true)));

        $files = $this->swoole->files;

        if (! \is_array($files)) {
            return;
        }

        foreach ($files as $key => $entry) {
            if (! \is_array($entry) || ! isset($entry['tmp_name'])) {
                continue;
            }

            [$entry['tmp_name'], $entry['error'], $entry['size']] = $this->dropPaths($entry['tmp_name'], $entry['error'] ?? null, $entry['size'] ?? null, $spoofed);
            $files[$key] = $entry;
        }

        $this->swoole->files = $files;
    }

    /**
     * Walk `tmp_name` with its parallel `error` and `size` trees, which nest
     * for bracketed field names such as `file[]` or `file[0][part]`.
     *
     * @param  list<string>  $spoofed
     * @return array{mixed, mixed, mixed}
     */
    private function dropPaths(mixed $path, mixed $error, mixed $size, array $spoofed): array
    {
        if (\is_string($path)) {
            if (\in_array($path, $spoofed, true)) {
                return ['', \UPLOAD_ERR_CANT_WRITE, 0];
            }

            return [$path, $error, $size];
        }

        if (! \is_array($path)) {
            return [$path, $error, $size];
        }

        foreach ($path as $position => $child) {
            [$path[$position], $childError, $childSize] = $this->dropPaths(
                $child,
                \is_array($error) ? $error[$position] ?? null : null,
                \is_array($size) ? $size[$position] ?? null : null,
                $spoofed,
            );

            if (\is_array($error)) {
                $error[$position] = $childError;
            }

            if (\is_array($size)) {
                $size[$position] = $childSize;
            }
        }

        return [$path, $error, $size];
    }

    /**
     * Paths named by `Swoole-Upload-File` part headers, which Swoole matches
     * case-insensitively at the start of a header line.
     *
     * @return list<string>
     */
    private function uploadMarkers(): array
    {
        $body = $this->swoole->rawContent();

        if (! \is_string($body)) {
            return [];
        }

        $markers = [];
        $offset = 0;

        while (($position = \stripos($body, self::UPLOAD_MARKER, $offset)) !== false) {
            $offset = $position + \strlen(self::UPLOAD_MARKER);

            if ($position === 0 || $body[$position - 1] !== "\n") {
                continue;
            }

            $end = \strpos($body, "\n", $offset);
            $line = \ltrim(\substr($body, $offset, $end === false ? null : $end - $offset), " \t");

            if (\str_starts_with($line, ':')) {
                $markers[] = \trim(\substr($line, 1));
            }
        }

        return $markers;
    }

    /**
     * @param array<string> $scopes
     */
    public function setScopes(array $scopes): void
    {
        $this->scopes = $scopes;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }
}
