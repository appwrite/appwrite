<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Exception as GQLException;
use Appwrite\Promises\Swoole;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\ID;
use Utopia\Exception;
use Utopia\Route;

class Resolvers
{
    /**
     * Create a resolver for a given API {@see Route}.
     *
     * @param App $utopia
     * @param ?Route $route
     * @return callable
     */
    public static function api(
        App $utopia,
        ?Route $route,
    ): callable {
        return static fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($utopia, $route, $args, $context, $info) {
                /** @var App $utopia */
                /** @var Response $response */
                /** @var Request $request */

                $utopia = $utopia->getResource('utopia:graphql', true);
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);

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
                        $request->setGet($args);
                        break;
                    default:
                        $request->setPost($args);
                        break;
                }

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for a document in a specified database and collection with a specific method type.
     *
     * @param App $utopia
     * @param Database $dbForProject
     * @param string $databaseId
     * @param string $collectionId
     * @param string $methodType
     * @return callable
     */
    public static function document(
        App $utopia,
        string $databaseId,
        string $collectionId,
        string $methodType,
    ): callable {
        return [self::class, 'document' . \ucfirst($methodType)](
            $utopia,
            $databaseId,
            $collectionId
        );
    }

    /**
     * Create a resolver for getting a document in a specified database and collection.
     *
     * @param App $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @return callable
     */
    public static function documentGet(
        App $utopia,
        string $databaseId,
        string $collectionId
    ): callable {
        return static fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $type, $args) {
                $utopia = $utopia->getResource('utopia:graphql', true);
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);

                $request->setMethod('GET');
                $request->setURI("/v1/database/collections/{$collectionId}/documents/{$args['documentId']}");

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for listing documents in a specified database and collection.
     *
     * @param App $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @return callable
     */
    public static function documentList(
        App $utopia,
        string $databaseId,
        string $collectionId,
    ): callable {
        return static fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $type, $args) {
                $utopia = $utopia->getResource('utopia:graphql', true);
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);

                $request->setMethod('GET');
                $request->setURI("/v1/database/collections/{$collectionId}/documents");
                $request->setGet([
                    'queries' => $args['queries'],
                ]);

                $beforeResolve = function ($payload) {
                    return $payload['documents'];
                };

                self::resolve($utopia, $request, $response, $resolve, $reject, $beforeResolve);
            }
        );
    }

    /**
     * Create a resolver for creating a document in a specified database and collection.
     *
     * @param App $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @return callable
     */
    public static function documentCreate(
        App $utopia,
        string $databaseId,
        string $collectionId,
    ): callable {
        return static fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $type, $args) {
                $utopia = $utopia->getResource('utopia:graphql', true);
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);

                $id = $args['id'] ?? ID::unique();
                $permissions = $args['permissions'] ?? null;

                unset($args['id']);
                unset($args['permissions']);

                $request->setMethod('POST');
                $request->setURI("/v1/databases/$databaseId/collections/$collectionId/documents");

                // Order must be the same as the route params
                $request->setPost([
                    'databaseId' => $databaseId,
                    'documentId' => $id,
                    'collectionId' => $collectionId,
                    'data' => $args,
                    'permissions' => $permissions,
                ]);

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for updating a document in a specified database and collection.
     *
     * @param App $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @return callable
     */
    public static function documentUpdate(
        App $utopia,
        string $databaseId,
        string $collectionId,
    ): callable {
        return static fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $type, $args) {
                $utopia = $utopia->getResource('utopia:graphql', true);
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);

                $documentId = $args['id'];
                $permissions = $args['permissions'] ?? null;

                unset($args['id']);
                unset($args['permissions']);

                $request->setMethod('PATCH');
                $request->setURI("/v1/databases/$databaseId/collections/$collectionId/documents/$documentId");

                // Order must be the same as the route params
                $request->setPost([
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => $documentId,
                    'data' => $args,
                    'permissions' => $permissions,
                ]);

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * Create a resolver for deleting a document in a specified database and collection.
     *
     * @param App $utopia
     * @param string $databaseId
     * @param string $collectionId
     * @return callable
     */
    public static function documentDelete(
        App $utopia,
        string $databaseId,
        string $collectionId
    ): callable {
        return static fn($type, $args, $context, $info) => new Swoole(
            function (callable $resolve, callable $reject) use ($utopia, $databaseId, $collectionId, $type, $args) {
                $utopia = $utopia->getResource('utopia:graphql', true);
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);

                $documentId = $args['id'];

                $request->setMethod('DELETE');
                $request->setURI("/v1/databases/$databaseId/collections/$collectionId/documents/$documentId");

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * @param App $utopia
     * @param Request $request
     * @param Response $response
     * @param callable $resolve
     * @param callable $reject
     * @param callable|null $beforeResolve
     * @param callable|null $beforeReject
     * @return void
     * @throws Exception
     */
    private static function resolve(
        App $utopia,
        Request $request,
        Response $response,
        callable $resolve,
        callable $reject,
        ?callable $beforeResolve = null,
        ?callable $beforeReject = null,
    ): void {
        // Drop json content type so post args are used directly
        if (\str_starts_with($request->getHeader('content-type'), 'application/json')) {
            $request->removeHeader('content-type');
        }

        $request = $request->clone();
        $utopia->setResource('request', static fn() => $request);
        $response->setContentType(Response::CONTENT_TYPE_NULL);

        try {
            $route = $utopia->match($request, fresh: true);

            $utopia->execute($route, $request);
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
            $reject(new GQLException(
                message: $payload['message'],
                code: $response->getStatusCode()
            ));
            return;
        }

        foreach ($payload as $key => $value) {
            if (\str_starts_with($key, '$')) {
                $escapedKey = \str_replace('$', '_', $key);
                $payload[$escapedKey] = $value;
                unset($payload[$key]);
            }
        }

        if ($beforeResolve) {
            $payload = $beforeResolve($payload);
        }

        $resolve($payload);
    }
}
