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

    public static function getExampleForValidator(string $name, array $param): string
    {
        $validator = (\is_callable($param['validator'])) ? call_user_func_array($param['validator'], (new App('UTC'))->getResources($param['injections'])) : $param['validator'];

        switch ((!empty($validator)) ? \get_class($validator) : '') {
            case 'Utopia\Validator\Text':
                return '[' . \strtoupper(Template::fromCamelCaseToSnake($name)) . ']';
            case 'Utopia\Validator\Boolean':
                return false;
                break;
            case 'Appwrite\Utopia\Database\Validator\CustomId':
                return '[' . \strtoupper(Template::fromCamelCaseToSnake($name)) . ']';
            case 'Utopia\Database\Validator\UID':
                return '[' . \strtoupper(Template::fromCamelCaseToSnake($name)) . ']';
            case 'Utopia\Database\Validator\DatetimeValidator':
                return '2022-06-15T13:45:30.496';
            case 'Appwrite\Network\Validator\Email':
                return 'email@example.com';
            case 'Appwrite\Network\Validator\URL':
                return 'https://example.com';
            case 'Utopia\Validator\JSON':
            case 'Utopia\Validator\Mock':
            case 'Utopia\Validator\Assoc':
                return '{}';
            case 'Utopia\Storage\Validator\File':
                return '';
            case 'Utopia\Validator\ArrayList':
                return '';
            case 'Utopia\Database\Validator\Permissions':
                return '["read(any)"]';
            case 'Utopia\Database\Validator\Roles':
                return '["any"]';
            case 'Appwrite\Auth\Validator\Password':
                return 'password';
            case 'Utopia\Validator\Range':
                /** @var \Utopia\Validator\Range $validator */
                return $validator->getMin();
            case 'Utopia\Validator\Numeric':
            case 'Utopia\Validator\Integer':
                return 0;
            case 'Utopia\Validator\FloatValidator':
                return 1.0;
            case 'Utopia\Validator\Length':
                return '';
            case 'Appwrite\Network\Validator\Host':
                return 'https://example.com';
            case 'Utopia\Validator\WhiteList':
                /** @var \Utopia\Validator\WhiteList $validator */
                return $validator->getList()[0];
            default:
                return '';
        }
    }
}