<?php

namespace Appwrite\Extend\SDK;

use Appwrite\Template\Template;
use Utopia\App;
use Utopia\Route;
use Utopia\Validator;

class RouteParser {
    public function __construct(private App $app) {}

    public function getRouteForMethod(string $service, string $method): ?Route {
        foreach ($this->app->getRoutes() as $key => $routes) {
            foreach ($routes as $route) { /** @var Route $route */
                $namespace = $route->getLabel('sdk.namespace', '');
                $routeMethod = $route->getLabel('sdk.method', '');
                if($service === $namespace && $routeMethod === $method) {
                    return $route;
                }
            }
        }
        return null;
    }
}