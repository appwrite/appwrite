<?php

namespace Appwrite\Utopia;

use Appwrite\Utopia\Request\Filter;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Route;
use Utopia\Swoole\Request as UtopiaRequest;

class Request extends UtopiaRequest
{
    /**
     * @var array<Filter>
     */
    private static array $filters = [];

    private static ?Route $route = null;

    public function __construct(SwooleRequest $request)
    {
        parent::__construct($request);
    }

    /**
     * @inheritdoc
     */
    public function getParams(): array
    {
        $parameters = parent::getParams();

        if (self::hasFilters() && self::hasRoute()) {
            $method = self::getRoute()->getLabel('sdk.method', 'unknown');
            $endpointIdentifier = self::getRoute()->getLabel('sdk.namespace', 'unknown') . '.' . $method;

            $parameters = array_reduce(
                self::getFilters(),
                fn (array $carry, Filter $filter) => $filter->parse($carry, $endpointIdentifier),
                $parameters
            );
        }

        return $parameters;
    }

    /**
     * Function to add a response filter, the order of filters are first in - first out.
     *
     * @param Filter $filter the response filter to set
     *
     * @return void
     */
    public static function addFilter(Filter $filter): void
    {
        self::$filters[] = $filter;
    }

    /**
     * Return the currently set filter
     *
     * @return array<Filter>
     */
    public static function getFilters(): array
    {
        return self::$filters;
    }

    /**
     * Reset filters
     *
     * @return void
     */
    public static function resetFilters(): void
    {
        self::$filters = [];
    }

    /**
     * Check if a filter has been set
     *
     * @return bool
     */
    public static function hasFilters(): bool
    {
        return !empty(self::$filters);
    }

    /**
     * Function to set a request route
     *
     * @param Route|null $route the request route to set
     *
     * @return void
     */
    public static function setRoute(?Route $route): void
    {
        self::$route = $route;
    }

    /**
     * Return the current route
     *
     * @return Route|null
     */
    public static function getRoute(): ?Route
    {
        return self::$route;
    }

    /**
     * Check if a route has been set
     *
     * @return bool
     */
    public static function hasRoute(): bool
    {
        return self::$route !== null;
    }

    /**
     * Get headers
     *
     * Method for getting all HTTP header parameters, including cookies.
     *
     * @return array<string,mixed>
     */
    public function getHeaders(): array
    {
        $headers = $this->generateHeaders();

        if (empty($this->swoole->cookie)) {
            return $headers;
        }

        $cookieHeaders = [];
        foreach ($this->swoole->cookie as $key => $value) {
            $cookieHeaders[] = "{$key}={$value}";
        }

        if (!empty($cookieHeaders)) {
            $headers['cookie'] = \implode('; ', $cookieHeaders);
        }

        return $headers;
    }

    /**
     * Get header
     *
     * Method for querying HTTP header parameters. If $key is not found $default value will be returned.
     *
     * @param  string  $key
     * @param  string  $default
     * @return string
     */
    public function getHeader(string $key, string $default = ''): string
    {
        $headers = $this->getHeaders();
        return $headers[$key] ?? $default;
    }
}
