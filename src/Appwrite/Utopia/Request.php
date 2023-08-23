<?php

namespace Appwrite\Utopia;

use Appwrite\Utopia\Request\Filter;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Route;
use Utopia\Swoole\Request as UtopiaRequest;

class Request extends UtopiaRequest
{
    private static ?Filter $filter = null;

    private static ?Route $route = null;

    public function __construct(SwooleRequest $request)
    {
        parent::__construct($request);
    }

    /**
     * {@inheritdoc}
     */
    public function getParams(): array
    {
        $parameters = parent::getParams();

        if (self::hasFilter() && self::hasRoute()) {
            $endpointIdentifier = self::getRoute()->getLabel('sdk.namespace', 'unknown').'.'.self::getRoute()->getLabel('sdk.method', 'unknown');
            $parameters = self::getFilter()->parse($parameters, $endpointIdentifier);
        }

        return $parameters;
    }

    /**
     * Function to set a response filter
     *
     * @param  Filter|null  $filter Filter the response filter to set
     * @return void
     */
    public static function setFilter(?Filter $filter): void
    {
        self::$filter = $filter;
    }

    /**
     * Return the currently set filter
     *
     * @return Filter|null
     */
    public static function getFilter(): ?Filter
    {
        return self::$filter;
    }

    /**
     * Check if a filter has been set
     *
     * @return bool
     */
    public static function hasFilter(): bool
    {
        return self::$filter != null;
    }

    /**
     * Function to set a request route
     *
     * @param  Route|null  $route the request route to set
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
        return self::$route != null;
    }
}
