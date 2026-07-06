<?php

namespace Appwrite\Utopia\Request;

use Appwrite\SDK\Method;
use Utopia\Http\Request;
use Utopia\Http\Route;

final class Filters
{
    /**
     * @param array<Filter> $filters
     */
    public static function apply(Request $request, Route $route, array $filters): void
    {
        if (empty($filters)) {
            return;
        }

        $methods = $route->getLabel('sdk', null);
        if (empty($methods)) {
            return;
        }

        if (!$methods instanceof Method && !\is_array($methods)) {
            return;
        }

        $parameters = $request->getParams();
        $id = self::getMethodId($methods, $parameters);

        foreach ($filters as $filter) {
            $parameters = $filter->parse($parameters, $id);
        }

        self::setParams($request, $parameters);
    }

    /**
     * @param Method|array<Method|null> $methods
     * @param array<string, mixed> $parameters
     */
    private static function getMethodId(Method|array $methods, array $parameters): string
    {
        if (!\is_array($methods)) {
            return $methods->getNamespace() . '.' . $methods->getMethodName();
        }

        $matched = null;
        foreach ($methods as $method) {
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

        return $matched !== null
            ? $matched->getNamespace() . '.' . $matched->getMethodName()
            : 'unknown.unknown';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function setParams(Request $request, array $parameters): void
    {
        match ($request->getMethod()) {
            Request::METHOD_POST,
            Request::METHOD_PUT,
            Request::METHOD_PATCH,
            Request::METHOD_DELETE => $request->setPayload($parameters),
            default => $request->setQueryString($parameters),
        };
    }
}
