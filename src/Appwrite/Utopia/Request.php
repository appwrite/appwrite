<?php

namespace Appwrite\Utopia;

use Appwrite\SDK\Method;
use Appwrite\Utopia\Request\Filter;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Http\Adapter\Swoole\Request as UtopiaRequest;
use Utopia\Http\Route;
use Utopia\System\System;

class Request extends UtopiaRequest
{
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
    }

    /**
     * @inheritdoc
     */
    public function getParams(): array
    {
        if ($this->filteredParams !== null) {
            return $this->filteredParams;
        }

        $parameters = parent::getParams();

        if (!$this->hasFilters() || !$this->hasRoute()) {
            return $parameters;
        }

        $methods = $this->route?->getLabel('sdk', null);

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
     * Check if a route has been set
     *
     * @return bool
     */
    public function hasRoute(): bool
    {
        return $this->route !== null;
    }

}
