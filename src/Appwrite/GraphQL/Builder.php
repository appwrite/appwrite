<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\JsonType;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Swoole\Coroutine\WaitGroup;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;
use Utopia\Route;
use Utopia\Validator;

use function Co\go;

class Builder
{
    protected static ?JsonType $jsonParser = null;

    protected static array $typeMapping = [];
    protected static array $defaultDocumentArgs = [];

    /**
     * Initialise the typeMapping array with the base cases of the recursion
     *
     * @return   void
     */
    public static function init(): void
    {
        self::$typeMapping = [
            Model::TYPE_BOOLEAN => Type::boolean(),
            Model::TYPE_STRING => Type::string(),
            Model::TYPE_INTEGER => Type::int(),
            Model::TYPE_FLOAT => Type::float(),
            Model::TYPE_JSON => self::json(),
            Response::MODEL_NONE => self::json(),
            Response::MODEL_ANY => self::json(),
        ];

        self::$defaultDocumentArgs = [
            'id' => [
                'id' => [
                    'type' => Type::string(),
                ],
            ],
            'list' => [
                'limit' => [
                    'type' => Type::int(),
                    'defaultValue' => 25,
                ],
                'offset' => [
                    'type' => Type::int(),
                    'defaultValue' => 0,
                ],
                'cursor' => [
                    'type' => Type::string(),
                    'defaultValue' => '',
                ],
                'cursorDirection' => [
                    'type' => Type::string(),
                    'defaultValue' => Database::CURSOR_AFTER,
                ],
                'orderAttributes' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => [],
                ],
                'orderType' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => [],
                ],
            ],
            'mutate' => [
                'read' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => ["role:member"],
                ],
                'write' => [
                    'type' => Type::listOf(Type::string()),
                    'defaultValue' => ["role:member"],
                ],
            ],
        ];
    }

    /**
     * Create a singleton for $jsonParser
     *
     * @return JsonType
     */
    public static function json(): JsonType
    {
        if (is_null(self::$jsonParser)) {
            self::$jsonParser = new JsonType();
        }
        return self::$jsonParser;
    }

    /**
     * Create a GraphQL type from a Utopia Model
     *
     * @param Model $model
     * @param Response $response
     * @return Type
     */
    private static function getModelTypeMapping(Model $model, Response $response): Type
    {
        if (isset(self::$typeMapping[$model->getType()])) {
            return self::$typeMapping[$model->getType()];
        }

        $rules = $model->getRules();
        $name = $model->getType();
        $fields = [];

        foreach ($rules as $key => $props) {
            $escapedKey = str_replace('$', '_', $key);

            $types = \is_array($props['type'])
                ? $props['type']
                : [$props['type']];

            foreach ($types as $type) {
                if (isset(self::$typeMapping[$type])) {
                    $type = self::$typeMapping[$type];
                } else {
                    try {
                        $complexModel = $response->getModel($type);
                        $type = self::getModelTypeMapping($complexModel, $response);
                    } catch (\Exception $e) {
                        Console::error("Could not find model for : {$type}");
                    }
                }

                if ($props['array']) {
                    $type = Type::listOf($type);
                }

                $fields[$escapedKey] = [
                    'type' => $type,
                    'description' => $props['description'],
                    'resolve' => fn($object, $args, $context, $info) => $object[$key],
                ];
            }
        }
        $objectType = [
            'name' => $name,
            'fields' => $fields
        ];
        self::$typeMapping[$name] = new ObjectType($objectType);

        return self::$typeMapping[$name];
    }

    /**
     * Map a Utopia\Validator to a valid GraphQL Type
     *
     * @param App $utopia
     * @param Validator|callable $validator
     * @param bool $required
     * @param array $injections
     * @return Type
     * @throws \Exception
     */
    private static function getParameterArgType(
        App $utopia,
        Validator|callable $validator,
        bool $required,
        array $injections
    ): Type {
        $validator = \is_callable($validator)
            ? \call_user_func_array($validator, $utopia->getResources($injections))
            : $validator;

        switch ((!empty($validator)) ? \get_class($validator) : '') {
            case 'Appwrite\Auth\Validator\Password':
            case 'Appwrite\Network\Validator\CNAME':
            case 'Appwrite\Network\Validator\Domain':
            case 'Appwrite\Network\Validator\Email':
            case 'Appwrite\Network\Validator\Host':
            case 'Appwrite\Network\Validator\IP':
            case 'Appwrite\Network\Validator\Origin':
            case 'Appwrite\Network\Validator\URL':
            case 'Appwrite\Task\Validator\Cron':
            case 'Appwrite\Utopia\Database\Validator\CustomId':
            case 'Appwrite\Storage\Validator\File':
            case 'Utopia\Database\Validator\Key':
            case 'Utopia\Database\Validator\CustomId':
            case 'Utopia\Database\Validator\UID':
            case 'Utopia\Storage\Validator\File':
            case 'Utopia\Validator\File':
            case 'Utopia\Validator\HexColor':
            case 'Utopia\Validator\Length':
            case 'Utopia\Validator\Text':
            case 'Utopia\Validator\WhiteList':
                $type = Type::string();
                break;
            case 'Utopia\Validator\Boolean':
                $type = Type::boolean();
                break;
            case 'Utopia\Validator\ArrayList':
                $type = Type::listOf(self::getParameterArgType(
                    $utopia,
                    $validator->getValidator(),
                    $required,
                    $injections
                ));
                break;
            case 'Utopia\Validator\Numeric':
            case 'Utopia\Validator\Integer':
            case 'Utopia\Validator\Range':
                $type = Type::int();
                break;
            case 'Utopia\Validator\FloatValidator':
                $type = Type::float();
                break;
            case 'Utopia\Database\Validator\Authorization':
            case 'Utopia\Database\Validator\Permissions':
                $type = Type::listOf(Type::string());
                break;
            case 'Utopia\Validator\Assoc':
            case 'Utopia\Validator\JSON':
            default:
                $type = self::json();
                break;
        }

        if ($required) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    /**
     * Map an Attribute type to a valid GraphQL Type
     *
     * @param string $type
     * @param bool $array
     * @param bool $required
     * @return Type
     * @throws \Exception
     */
    private static function getAttributeArgType(string $type, bool $array, bool $required): Type
    {
        if ($array) {
            return Type::listOf(self::getAttributeArgType($type, false, $required));
        }
        $type = match ($type) {
            'boolean' => Type::boolean(),
            'integer' => Type::int(),
            'double' => Type::float(),
            default => Type::string(),
        };

        if ($required) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    /**
     * @throws \Exception
     */
    public static function buildSchema(
        App $utopia,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $user,
    ): Schema {
        $apiSchema = self::buildAPISchema($utopia, $request, $response);
        $db = self::buildCollectionsSchema($utopia, $request, $response, $dbForProject, $user);

        $queryFields = \array_merge_recursive($apiSchema['query'], $db['query']);
        $mutationFields = \array_merge_recursive($apiSchema['mutation'], $db['mutation']);

        ksort($queryFields);
        ksort($mutationFields);

        return new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => $queryFields
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutationFields
            ])
        ]);
    }

    /**
     * This function iterates all API routes and builds a
     * GraphQL schema defining types (and resolvers) for all response models
     *
     * @param App $utopia
     * @param Request $request
     * @param Response $response
     * @return array
     * @throws \Exception
     */
    public static function buildAPISchema(App $utopia, Request $request, Response $response): array
    {
        $start = microtime(true);

        self::init();
        $queryFields = [];
        $mutationFields = [];

        foreach ($utopia->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                if (str_starts_with($route->getPath(), '/v1/mock/')) {
                    continue;
                }

                $namespace = $route->getLabel('sdk.namespace', '');
                $methodName = $namespace . \ucfirst($route->getLabel('sdk.method', ''));
                $responseModelNames = $route->getLabel('sdk.response.model', "none");

                // TODO: Handle "none" responses
                if ($responseModelNames === "none") {
                    continue;
                }

                $responseModels = \is_array($responseModelNames)
                    ? \array_map(static fn($m) => $response->getModel($m), $responseModelNames)
                    : [$response->getModel($responseModelNames)];

                foreach ($responseModels as $responseModel) {
                    $type = self::getModelTypeMapping($responseModel, $response);
                    $description = $route->getDesc();
                    $args = [];

                    foreach ($route->getParams() as $key => $value) {
                        $argType = self::getParameterArgType(
                            $utopia,
                            $value['validator'],
                            !$value['optional'],
                            $value['injections']
                        );
                        $args[$key] = [
                            'type' => $argType,
                            'description' => $value['description'],
                            'defaultValue' => $value['default']
                        ];
                    }

                    $resolve = self::resolveAPIRequest($utopia, $request, $response, $route);

                    $field = [
                        'type' => $type,
                        'description' => $description,
                        'args' => $args,
                        'resolve' => $resolve
                    ];

                    switch ($method) {
                        case 'GET':
                            $queryFields[$methodName] = $field;
                            break;
                        case 'POST':
                        case 'PUT':
                        case 'PATCH':
                        case 'DELETE':
                            $mutationFields[$methodName] = $field;
                            break;
                        default:
                            throw new \Exception("Unsupported method: $method");
                    }
                }
            }
        }
        $time_elapsed_secs = (microtime(true) - $start) * 1000;
        Console::info("[INFO] Built GraphQL REST API Schema in ${time_elapsed_secs}ms");

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    /**
     * @param App $utopia
     * @param Response $response
     * @param Request $request
     * @param mixed $route
     * @return callable
     */
    private static function resolveAPIRequest(
        App $utopia,
        Request $request,
        Response $response,
        mixed $route,
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $request, $response, $route, $args, $context, $info) {
                // Mutate the original request object to match route
                $swoole = $request->getSwoole();
                $swoole->server['request_method'] = $route->getMethod();
                $swoole->server['request_uri'] = $route->getPath();
                $swoole->server['path_info'] = $route->getPath();
                $swoole->post = $args;

                self::resolve($utopia, $swoole, $response, $resolve, $reject);
            }
        );
    }

    /**
     * This function iterates all a projects attributes and builds
     * GraphQL queries and mutations for the collections they make up.
     *
     * @param App $utopia
     * @param Request $request
     * @param Response $response
     * @param Database $dbForProject
     * @param Document|null $user
     * @return array
     * @throws \Exception
     */
    public static function buildCollectionsSchema(
        App $utopia,
        Request $request,
        Response $response,
        Database $dbForProject,
        ?Document $user = null,
    ): array {
        $start = microtime(true);

        $userId = $user?->getId();
        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 1000;
        $offset = 0;
        $count = 0;

        $wg = new WaitGroup();

        while (
            !empty($attrs = Authorization::skip(fn() => $dbForProject->find(
                'attributes',
                limit: $limit,
                offset: $offset
            )))
        ) {
            $wg->add();
            $count += count($attrs);
            go(function () use ($utopia, $request, $response, $dbForProject, &$collections, &$queryFields, &$mutationFields, $limit, &$offset, $attrs, $userId, $wg) {
                foreach ($attrs as $attr) {
                    $collectionId = $attr->getAttribute('collectionId');

                    if ($attr->getAttribute('status') !== 'available') {
                        continue;
                    }
                    $key = $attr->getAttribute('key');
                    $type = $attr->getAttribute('type');
                    $array = $attr->getAttribute('array');
                    $required = $attr->getAttribute('required');
                    $escapedKey = str_replace('$', '_', $key);
                    $collections[$collectionId][$escapedKey] = [
                        'type' => self::getAttributeArgType($type, $array, $required),
                    ];
                }

                foreach ($collections as $collectionId => $attributes) {
                    $objectType = new ObjectType([
                        'name' => $collectionId,
                        'fields' => $attributes
                    ]);

                    $attributes = \array_merge(
                        $attributes,
                        self::$defaultDocumentArgs['mutate']
                    );

                    $queryFields[$collectionId . 'Get'] = [
                        'type' => $objectType,
                        'args' => self::$defaultDocumentArgs['id'],
                        'resolve' => self::resolveDocumentGet($utopia, $request, $response, $dbForProject, $collectionId)
                    ];
                    $queryFields[$collectionId . 'List'] = [
                        'type' => $objectType,
                        'args' => self::$defaultDocumentArgs['list'],
                        'resolve' => self::resolveDocumentList($utopia, $request, $response, $dbForProject, $collectionId)
                    ];
                    $mutationFields[$collectionId . 'Create'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => self::resolveDocumentMutate($utopia, $request, $response, $dbForProject, $collectionId, 'POST')
                    ];
                    $mutationFields[$collectionId . 'Update'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => self::resolveDocumentMutate($utopia, $request, $response, $dbForProject, $collectionId, 'PATCH')
                    ];
                    $mutationFields[$collectionId . 'Delete'] = [
                        'type' => $objectType,
                        'args' => self::$defaultDocumentArgs['id'],
                        'resolve' => self::resolveDocumentDelete($utopia, $request, $response, $dbForProject, $collectionId)
                    ];
                }
                $wg->done();
            });
            $offset += $limit;
        }
        $wg->wait();

        $time_elapsed_secs = (microtime(true) - $start) * 1000;
        Console::info('[INFO] Built GraphQL Project Collection Schema (' . $count . ' attributes) in ' . $time_elapsed_secs . 'ms');

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    private static function resolveDocumentGet(
        App $utopia,
        Request $request,
        Response $response,
        Database $dbForProject,
        string $collectionId
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $request, $response, $dbForProject, $collectionId, $type, $args) {
                try {
                    $swoole = $request->getSwoole();
                    $swoole->post = [
                        'collectionId' => $collectionId,
                        'documentId' => $args['id'],
                    ];
                    $swoole->server['request_method'] = 'GET';
                    $swoole->server['request_uri'] = "/v1/database/collections/$collectionId/documents/{$args['id']}";
                    $swoole->server['path_info'] = "/v1/database/collections/$collectionId/documents/{$args['id']}";

                    self::resolve($utopia, $swoole, $response, $resolve, $reject);
                } catch (\Throwable $e) {
                    $reject($e);
                    return;
                }
            }
        );
    }

    private static function resolveDocumentList(
        App $utopia,
        Request $request,
        Response $response,
        Database $dbForProject,
        string $collectionId
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $request, $response, $dbForProject, $collectionId, $type, $args) {
                $swoole = $request->getSwoole();
                $swoole->post = [
                    'collectionId' => $collectionId,
                    'limit' => $args['limit'],
                    'offset' => $args['offset'],
                    'cursor' => $args['cursor'],
                    'cursorDirection' => $args['cursorDirection'],
                    'orderAttributes' => $args['orderAttributes'],
                    'orderType' => $args['orderType'],
                ];
                $swoole->server['request_method'] = 'GET';
                $swoole->server['request_uri'] = "/v1/database/collections/$collectionId/documents";
                $swoole->server['path_info'] = "/v1/database/collections/$collectionId/documents";

                self::resolve($utopia, $swoole, $response, $resolve, $reject);
            }
        );
    }

    private static function resolveDocumentMutate(
        App $utopia,
        Request $request,
        Response $response,
        Database $dbForProject,
        string $collectionId,
        string $method,
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $request, $response, $dbForProject, $collectionId, $method, $type, $args) {
                $swoole = $request->getSwoole();

                $id = $args['id'] ?? 'unique()';
                $read = $args['read'];
                $write = $args['write'];

                unset($args['id']);
                unset($args['read']);
                unset($args['write']);

                // Order must be the same as the route params
                $swoole->post = [
                    'documentId' => $id,
                    'collectionId' => $collectionId,
                    'data' => $args,
                    'read' => $read,
                    'write' => $write,
                ];
                $swoole->server['request_method'] = $method;
                $swoole->server['request_uri'] = "/v1/database/collections/$collectionId/documents";
                $swoole->server['path_info'] = "/v1/database/collections/$collectionId/documents";

                self::resolve($utopia, $swoole, $response, $resolve, $reject);
            }
        );
    }

    private static function resolveDocumentDelete(
        App $utopia,
        Request $request,
        Response $response,
        Database $dbForProject,
        string $collectionId
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $request, $response, $dbForProject, $collectionId, $type, $args) {
                $swoole = $request->getSwoole();
                $swoole->post = [
                    'collectionId' => $collectionId,
                    'documentId' => $args['id'],
                ];
                $swoole->server['request_method'] = 'DELETE';
                $swoole->server['request_uri'] = "/v1/database/collections/$collectionId/documents/{$args['id']}";
                $swoole->server['path_info'] = "/v1/database/collections/$collectionId/documents/{$args['id']}";

                self::resolve($utopia, $swoole, $response, $resolve, $reject);
            }
        );
    }

    /**
     * @param App $utopia
     * @param \Swoole\Http\Request $swoole
     * @param Response $response
     * @param callable $resolve
     * @param callable $reject
     * @return void
     * @throws \Utopia\Exception
     */
    private static function resolve(
        App $utopia,
        \Swoole\Http\Request $swoole,
        Response $response,
        callable $resolve,
        callable $reject,
    ): void {
        // Drop json content type so post args are used directly
        if (
            \array_key_exists('content-type', $swoole->header)
            && $swoole->header['content-type'] === 'application/json'
        ) {
            unset($swoole->header['content-type']);
        }

        $gqlResponse = $response;

        $request = new Request($swoole);
        $apiResponse = new Response($response->getSwoole());
        $apiResponse->setContentType(Response::CONTENT_TYPE_NULL);

        $utopia->setResource('request', fn() => $request);
        $utopia->setResource('response', fn() => $apiResponse);

        try {
            // Set route to null so match doesn't early return the GraphQL route
            // Then get the route match the request so path matches are populated
            $route = $utopia->setRoute(null)->match($request);

            $utopia->execute($route, $request);
        } catch (\Throwable $e) {
            $gqlResponse->setStatusCode($apiResponse->getStatusCode());
            $reject($e);
            return;
        }

        $result = $apiResponse->getPayload();

        $gqlResponse->setStatusCode($apiResponse->getStatusCode());

        if ($apiResponse->getStatusCode() < 200 || $apiResponse->getStatusCode() >= 400) {
            $reject(new GQLException($result['message'], $apiResponse->getStatusCode()));
            return;
        }

        // Add headers and cookies from inner to outer response
        // TODO: Add setters to response to allow setting entire array at once
        foreach ($apiResponse->getHeaders() as $key => $value) {
            $gqlResponse->addHeader($key, $value);
        }
        foreach ($apiResponse->getCookies() as $name => $cookie) {
            $gqlResponse->addCookie($name, $cookie['value'], $cookie['expire'], $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }


        $resolve($result);
    }
}
