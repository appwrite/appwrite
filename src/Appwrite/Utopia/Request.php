<?php

namespace Appwrite\Utopia;

use Appwrite\Auth\Auth;
use Appwrite\Utopia\Request\Filter;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Validator\Authorization;
use Utopia\Route;
use Utopia\Swoole\Request as UtopiaRequest;

class Request extends UtopiaRequest
{
    /**
     * @var array<Filter>
     */
    private array $filters = [];
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

        if ($this->hasFilters() && self::hasRoute()) {
            $methods = self::getRoute()->getLabel('sdk', null);

            if (!\is_array($methods)) {
                $methods = [$methods];
            }

            $params = [];

            foreach ($methods as $method) {
                /** @var \Appwrite\SDK\Method $method */
                if (empty($method)) {
                    $endpointIdentifier = 'unknown.unknown';
                } else {
                    $endpointIdentifier = $method->getNamespace() . '.' . $method->getMethodName();
                }

                $params += $method->getParameters();
            }

            if (!empty($params)) {
                $parameters = array_filter($parameters, function ($key) use ($params) {
                    return array_key_exists($key, $params);
                }, \ARRAY_FILTER_USE_KEY);
            }

            foreach ($this->getFilters() as $filter) {
                $parameters = $filter->parse($parameters, $endpointIdentifier);
            }
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
    public function addFilter(Filter $filter): void
    {
        $this->filters[] = $filter;
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
        try {
            $headers = $this->generateHeaders();
        } catch (\Throwable) {
            $headers = [];
        }

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
        $forwardedUserAgent = $this->getHeader('x-forwarded-user-agent');
        if (!empty($forwardedUserAgent)) {
            $roles = Authorization::getRoles();
            $isAppUser = Auth::isAppUser($roles);

            if ($isAppUser) {
                return $forwardedUserAgent;
            }
        }

        return UtopiaRequest::getUserAgent($default);
    }
}
