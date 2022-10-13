<?php

namespace Appwrite\Utopia;

use Appwrite\Utopia\Request\Filter;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Route;
use Utopia\Swoole\Request as UtopiaRequest;

class Request extends UtopiaRequest
{
    /**
     * @var Filter
     */
    private static $filter = null;

    /**
     * @var Route
     */
    private static $route = null;

    /**
     * Request constructor.
     */
    public function __construct(SwooleRequest $request)
    {
        parent::__construct($request);
    }

    public function clone(): Request
    {
        return new self($this->swoole);
    }

    /**
     * Get Params
     *
     * Get all params of current method
     *
     * @return array
     */
    public function getParams(): array
    {
        $requestParameters = [];

        switch ($this->getMethod()) {
            case self::METHOD_GET:
                $requestParameters = (!empty($this->swoole->get)) ? $this->swoole->get : [];
                break;
            case self::METHOD_POST:
            case self::METHOD_PUT:
            case self::METHOD_PATCH:
            case self::METHOD_DELETE:
                $requestParameters = $this->generateInput();
                break;
            default:
                $requestParameters = (!empty($this->swoole->get)) ? $this->swoole->get : [];
        }

        if (self::hasFilter() && self::hasRoute()) {
            $endpointIdentifier = self::getRoute()->getLabel('sdk.namespace', 'unknown') . '.' . self::getRoute()->getLabel('sdk.method', 'unknown');
            $requestParameters = self::getFilter()->parse($requestParameters, $endpointIdentifier);
        }

        return $requestParameters;
    }


    /**
     * Function to set a response filter
     *
     * @param $filter the response filter to set
     *
     * @return void
     */
    public static function setFilter(?Filter $filter)
    {
        self::$filter = $filter;
    }

    /**
     * Return the currently set filter
     *
     * @return Filter
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
     * @param Route $route the request route to set
     *
     * @return void
     */
    public static function setRoute(?Route $route)
    {
        self::$route = $route;
    }

    /**
     * Return the currently get route
     *
     * @return Route
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
