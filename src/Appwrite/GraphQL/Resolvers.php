<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Exception as GQLException;
use Appwrite\Promises\Swoole;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\DI\Container;
use Utopia\Exception;
use Utopia\Http\Response as UtopiaHttpResponse;
use Utopia\Http\Request as UtopiaHttpRequest;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\System\System;

class Resolvers
{
    /**
     * Create a resolver for a given API {@see Route}.
     *
     * @param Http $http
     * @param ?Route $route
     * @return callable
     */
    public function api(
        Http $http,
        ?Route $route,
        UtopiaHttpRequest $request,
        UtopiaHttpResponse $response,
        Container $container,
    ): callable {
        $resolver = $this;
        return fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($http, $route, $args, $context, $container, $info, $request, $response, $resolver) {
                $path = $route->getPath();
                foreach ($args as $key => $value) {
                    if (\str_contains($path, '/:' . $key)) {
                        $path = \str_replace(':' . $key, $value, $path);
                    }
                }

                $request->setMethod($route->getMethod());
                $request->setURI($path);

                switch ($route->getMethod()) {
                    case 'GET':
                        $request->setQuery($args);
                        break;
                    default:
                        $request->setPayload($args);
                        break;
                }

                $resolver->resolve($http, $request, $response, $container, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for a document in a specified database and collection with a specific method type.
     *
     * @param Http $http
     * @param string $databaseId
     * @param string $collectionId
     * @param string $methodType
     * @return callable
     */
    public function document(
        Http $http,
        string $databaseId,
        string $collectionId,
        string $methodType,
    ): callable {
        return [self::class, 'document' . \ucfirst($methodType)](
            $http,
            $databaseId,
            $collectionId
        );
    }

    /**
     * Create a resolver for getting a document in a specified database and collection.
     *
     * @param Http $http
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @return callable
     */
    public function documentGet(
        Http $http,
        string $databaseId,
        string $collectionId,
        callable $url,
        UtopiaHttpRequest $request,
        UtopiaHttpResponse $response,
        Container $container,
    ): callable {
         $resolver = $this;
        return fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($http, $databaseId, $collectionId, $url, $type, $args, $container, $request, $response, $resolver) {
                $request->setMethod('GET');
                $request->setURI($url($databaseId, $collectionId, $args));

                $resolver->resolve($http, $request, $response, $container, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for listing documents in a specified database and collection.
     *
     * @param Http $http
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @param callable $params
     * @return callable
     */
    public function documentList(
        Http $http,
        string $databaseId,
        string $collectionId,
        callable $url,
        callable $params,
        UtopiaHttpRequest $request,
        UtopiaHttpResponse $response,
        Container $container,
    ): callable {
         $resolver = $this;
        return fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($http, $databaseId, $collectionId, $url, $params, $type, $args, $container, $request, $response, $resolver) {
                $request->setMethod('GET');
                $request->setURI($url($databaseId, $collectionId, $args));
                $request->setQuery($params($databaseId, $collectionId, $args));

                $beforeResolve = function ($payload) {
                    return $payload['documents'];
                };

                $resolver->resolve($http, $request, $response, $container, $resolve, $reject, $beforeResolve);
            }
        );
    }

    /**
     * Create a resolver for creating a document in a specified database and collection.
     *
     * @param Http $http
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @param callable $params
     * @return callable
     */
    public function documentCreate(
        Http $http,
        string $databaseId,
        string $collectionId,
        callable $url,
        callable $params,
        UtopiaHttpRequest $request,
        UtopiaHttpResponse $response,
        Container $container,
    ): callable {
         $resolver = $this;
        return fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($http, $databaseId, $collectionId, $url, $params, $type, $args, $container, $request, $response, $resolver) {
                $request->setMethod('POST');
                $request->setURI($url($databaseId, $collectionId, $args));
                $request->setPayload($params($databaseId, $collectionId, $args));

                $resolver->resolve($http, $request, $response, $container, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for updating a document in a specified database and collection.
     *
     * @param Http $http
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @param callable $params
     * @return callable
     */
    public function documentUpdate(
        Http $http,
        string $databaseId,
        string $collectionId,
        callable $url,
        callable $params,
        UtopiaHttpRequest $request,
        UtopiaHttpResponse $response,
        Container $container,
    ): callable {
         $resolver = $this;
        return fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($http, $databaseId, $collectionId, $url, $params, $type, $args, $container, $request, $response, $resolver) {
                $request->setMethod('PATCH');
                $request->setURI($url($databaseId, $collectionId, $args));
                $request->setPayload($params($databaseId, $collectionId, $args));

                $resolver->resolve($http, $request, $response, $container, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for deleting a document in a specified database and collection.
     *
     * @param Http $http
     * @param string $databaseId
     * @param string $collectionId
     * @param callable $url
     * @return callable
     */
    public function documentDelete(
        Http $http,
        string $databaseId,
        string $collectionId,
        callable $url,
        UtopiaHttpRequest $request,
        UtopiaHttpResponse $response,
        Container $container,
    ): callable {
         $resolver = $this;
        return fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($http, $databaseId, $collectionId, $url, $type, $args, $container, $request, $response, $resolver) {
                $request->setMethod('DELETE');
                $request->setURI($url($databaseId, $collectionId, $args));

                $resolver->resolve($http, $request, $response, $container, $resolve, $reject);
            }
        );
    }

    /**
     * @param Http $http
     * @param Request $request
     * @param Response $response
     * @param callable $resolve
     * @param callable $reject
     * @param callable|null $beforeResolve
     * @param callable|null $beforeReject
     * @return void
     * @throws Exception
     */
    private function resolve(
        Http $http,
        Request $request,
        Response $response,
        Container $context,
        callable $resolve,
        callable $reject,
        ?callable $beforeResolve = null,
        ?callable $beforeReject = null,
    ): void {
        var_dump('HEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE');
        // Drop json content type so post args are used directly
        if (\str_starts_with($request->getHeader('content-type'), 'application/json')) {
            $request->removeHeader('content-type');
        }

        $response->setContentType(Response::CONTENT_TYPE_NULL);

        try {
            $route = $http->match($request);

            $http->execute($route, $request, $context);
        } catch (\Throwable $e) {
            if ($beforeReject) {
                $e = $beforeReject($e);
            }
            $reject($e);
            return;
        }

        $payload = $response->getPayload();

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            if ($beforeReject) {
                $payload = $beforeReject($payload);
            }
            $reject(
                new GQLException(
                    message: $payload['message'],
                    code: $response->getStatusCode()
                )
            );
            return;
        }

        $payload = self::escapePayload($payload, 1);

        if ($beforeResolve) {
            $payload = $beforeResolve($payload);
        }

        $resolve($payload);
    }

    private static function escapePayload(array $payload, int $depth)
    {
        if ($depth > System::getEnv('_APP_GRAPHQL_MAX_DEPTH', 3)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (\str_starts_with($key, '$')) {
                $escapedKey = \str_replace('$', '_', $key);
                $payload[$escapedKey] = $value;
                unset($payload[$key]);
            }

            if (\is_array($value)) {
                $payload[$key] = self::escapePayload($value, $depth + 1);
            }
        }

        return $payload;
    }
}
