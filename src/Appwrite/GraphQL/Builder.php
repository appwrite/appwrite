<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\InputFile;
use Appwrite\GraphQL\Types\Json;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Route;
use Utopia\Validator;

use function Co\go;

class Builder
{
    protected static ?Json $jsonType = null;
    protected static ?InputFile $inputFile = null;

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

    public static function json(): Json
    {
        if (is_null(self::$jsonType)) {
            self::$jsonType = new Json();
        }
        return self::$jsonType;
    }

    public static function inputFile(): InputFile
    {
        if (is_null(self::$inputFile)) {
            self::$inputFile = new InputFile();
        }
        return self::$inputFile;
    }

    /**
     * Create a GraphQL type from a Utopia Model
     *
     * @param Model $model
     * @param array $models
     * @return Type
     */
    private static function getModelTypeMapping(Model $model, array $models): Type
    {
        if (isset(self::$typeMapping[$model->getType()])) {
            return self::$typeMapping[$model->getType()];
        }

        $rules = $model->getRules();
        $name = $model->getType();
        $fields = [];

        if ($model->isAny()) {
            $fields['data'] = [
                'type' => Type::string(),
                'description' => 'Data field',
                'resolve' => fn($object, $args, $context, $info) => \json_encode($object, JSON_FORCE_OBJECT),
            ];
        }

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
                        $complexModel = $models[$type];
                        $type = self::getModelTypeMapping($complexModel, $models);
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
    private static function getParameterType(
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
            case 'Appwrite\Event\Validator\Event':
            case 'Appwrite\Network\Validator\CNAME':
            case 'Appwrite\Network\Validator\Domain':
            case 'Appwrite\Network\Validator\Email':
            case 'Appwrite\Network\Validator\Host':
            case 'Appwrite\Network\Validator\IP':
            case 'Appwrite\Network\Validator\Origin':
            case 'Appwrite\Network\Validator\URL':
            case 'Appwrite\Task\Validator\Cron':
            case 'Appwrite\Utopia\Database\Validator\CustomId':
            case 'Utopia\Database\Validator\Key':
            case 'Utopia\Database\Validator\CustomId':
            case 'Utopia\Database\Validator\UID':
            case 'Utopia\Validator\HexColor':
            case 'Utopia\Validator\Length':
            case 'Utopia\Validator\Text':
            case 'Utopia\Validator\WhiteList':
            default:
                $type = Type::string();
                break;
            case 'Utopia\Validator\Boolean':
                $type = Type::boolean();
                break;
            case 'Utopia\Validator\ArrayList':
                $type = Type::listOf(self::getParameterType(
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
                $type = self::json();
                break;
            case 'Utopia\Storage\Validator\File':
                $type = self::inputFile();
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
    private static function getAttributeType(string $type, bool $array, bool $required): Type
    {
        if ($array) {
            return Type::listOf(self::getAttributeType($type, false, $required));
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
        Database $dbForProject,
        Document $user,
    ): Schema {
        $start = microtime(true);
        $register = $utopia->getResource('register');
        $envVersion = App::getEnv('_APP_VERSION');
        $schemaVersion = $register->has('apiSchemaVersion') ? $register->get('apiSchemaVersion') : '';
        $apiSchema = $register->has('apiSchema') ? $register->get('apiSchema') : false;

        if (!$apiSchema || \version_compare($envVersion, $schemaVersion, "!=")) {
            $apiSchema = self::buildAPISchema($utopia);
            $register->set('apiSchema', fn() => $apiSchema);
            $register->set('apiSchemaVersion', fn() => $envVersion);
        }

        $collectionSchemaDirty = $register->has('schemaDirty')
            && $register->get('schemaDirty');

        if ($register->has('collectionSchema') && !$collectionSchemaDirty) {
            $collectionSchema = $register->get('collectionSchema');
        } else {
            $collectionSchema = self::buildCollectionSchema($utopia, $dbForProject, $user);
            $register->set('collectionSchema', fn() => $collectionSchema);
            $register->set('schemaDirty', fn() => false);
        }

//        $changeSet = $cache->load('collectionChangeSet', INF);
//        if ($collectionSchema && $collectionSchemaDirty) {
//            foreach ($changeSet as $change) {
//                $collectionSchema = self::applyChange($collectionSchema, $change);
//            }
//        } elseif (!$collectionSchema) {
//            $collectionSchema = self::buildCollectionsSchema($utopia, $dbForProject, $user);
//        }

        $queryFields = \array_merge_recursive($apiSchema['query'], $collectionSchema['query']);
        $mutationFields = \array_merge_recursive($apiSchema['mutation'], $collectionSchema['mutation']);

        ksort($queryFields);
        ksort($mutationFields);

        $timeElapsedMillis = (microtime(true) - $start) * 1000;
        $timeElapsedMillis = \number_format((float) $timeElapsedMillis, 3, '.', '');
        Console::info('[INFO] Built GraphQL Schema in ' . $timeElapsedMillis . 'ms');

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
     * @return array
     * @throws \Exception
     */
    public static function buildAPISchema(App $utopia): array
    {
        $start = microtime(true);

        self::init();
        $queryFields = [];
        $mutationFields = [];
        $response = new Response(new SwooleResponse());
        $models = $response->getModels();

        foreach (App::getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                if (str_starts_with($route->getPath(), '/v1/mock/')) {
                    continue;
                }

                $namespace = $route->getLabel('sdk.namespace', '');
                $methodName = $namespace . \ucfirst($route->getLabel('sdk.method', ''));
                $responseModelNames = $route->getLabel('sdk.response.model', "none");

                $responseModels = \is_array($responseModelNames)
                    ? \array_map(static fn($m) => $models[$m], $responseModelNames)
                    : [$models[$responseModelNames]];

                foreach ($responseModels as $responseModel) {
                    $type = self::getModelTypeMapping($responseModel, $models);
                    $description = $route->getDesc();
                    $args = [];

                    foreach ($route->getParams() as $key => $value) {
                        $argType = self::getParameterType(
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

                    $field = [
                        'type' => $type,
                        'description' => $description,
                        'args' => $args,
                        'resolve' => self::resolveAPIRequest($utopia, $route)
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
        $timeElapsedMillis = (microtime(true) - $start) * 1000;
        $timeElapsedMillis = \number_format((float) $timeElapsedMillis, 3, '.', '');
        Console::info("[INFO] Built GraphQL REST API Schema in ${timeElapsedMillis}ms");

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    /**
     * @param App $utopia
     * @param ?Route $route
     * @return callable
     */
    private static function resolveAPIRequest(
        App $utopia,
        ?Route $route,
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $route, $args, $context, $info) {
                // Mutate the original request object to match route
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);
                $swoole = $request->getSwoole();
                $swoole->server['request_method'] = $route->getMethod();
                $swoole->server['request_uri'] = $route->getPath();
                $swoole->server['path_info'] = $route->getPath();

                switch ($route->getMethod()) {
                    case 'GET':
                        $swoole->get = $args;
                        break;
                    default:
                        $swoole->post = $args;
                        break;
                }

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * This function iterates all a projects attributes and builds
     * GraphQL queries and mutations for the collections they make up.
     *
     * @param App $utopia
     * @param Database $dbForProject
     * @param Document|null $user
     * @return array
     * @throws \Exception
     */
    public static function buildCollectionSchema(
        App $utopia,
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
            go(function () use ($utopia, $dbForProject, &$collections, &$queryFields, &$mutationFields, $limit, &$offset, $attrs, $userId, $wg) {
                foreach ($attrs as $attr) {
                    if ($attr->getAttribute('status') !== 'available') {
                        continue;
                    }
                    $databaseId = $attr->getAttribute('databaseId');
                    $collectionId = $attr->getAttribute('collectionId');
                    $key = $attr->getAttribute('key');
                    $type = $attr->getAttribute('type');
                    $array = $attr->getAttribute('array');
                    $required = $attr->getAttribute('required');
                    $escapedKey = str_replace('$', '_', $key);
                    $collections[$collectionId][$escapedKey] = [
                        'type' => self::getAttributeType($type, $array, $required),
                    ];
                }

                foreach ($collections as $collectionId => $attributes) {
                    $objectType = new ObjectType([
                        'name' => $collectionId,
                        'fields' => \array_merge(["_id" => ['type' => Type::string()]], $attributes),
                    ]);

                    $attributes = \array_merge(
                        $attributes,
                        self::$defaultDocumentArgs['mutate']
                    );

                    $queryFields[$collectionId . 'Get'] = [
                        'type' => $objectType,
                        'args' => self::$defaultDocumentArgs['id'],
                        'resolve' => self::resolveDocumentGet($utopia, $dbForProject, $databaseId, $collectionId)
                    ];
                    $queryFields[$collectionId . 'List'] = [
                        'type' => $objectType,
                        'args' => self::$defaultDocumentArgs['list'],
                        'resolve' => self::resolveDocumentList($utopia, $dbForProject, $databaseId, $collectionId)
                    ];
                    $mutationFields[$collectionId . 'Create'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => self::resolveDocumentMutate($utopia, $dbForProject, $databaseId, $collectionId, 'POST')
                    ];
                    $mutationFields[$collectionId . 'Update'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => self::resolveDocumentMutate($utopia, $dbForProject, $databaseId, $collectionId, 'PATCH')
                    ];
                    $mutationFields[$collectionId . 'Delete'] = [
                        'type' => $objectType,
                        'args' => self::$defaultDocumentArgs['id'],
                        'resolve' => self::resolveDocumentDelete($utopia, $dbForProject, $databaseId, $collectionId)
                    ];
                }
                $wg->done();
            });
            $offset += $limit;
        }
        $wg->wait();

        $timeElapsedMillis = (microtime(true) - $start) * 1000;
        $timeElapsedMillis = \number_format((float) $timeElapsedMillis, 3, '.', '');
        Console::info('[INFO] Built GraphQL Project Collection Schema in ' . $timeElapsedMillis . 'ms (' . $count . ' attributes)');

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    private static function resolveDocumentGet(
        App $utopia,
        Database $dbForProject,
        string $databaseId,
        string $collectionId
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $dbForProject, $databaseId, $collectionId, $type, $args) {
                try {
                    $request = $utopia->getResource('request', true);
                    $response = $utopia->getResource('response', true);
                    $swoole = $request->getSwoole();
                    $swoole->post = [
                        'databaseId' => $databaseId,
                        'collectionId' => $collectionId,
                        'documentId' => $args['id'],
                    ];
                    $swoole->server['request_method'] = 'GET';
                    $swoole->server['request_uri'] = "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['id']}";
                    $swoole->server['path_info'] = "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['id']}";

                    self::resolve($utopia, $request, $response, $resolve, $reject);
                } catch (\Throwable $e) {
                    $reject($e);
                    return;
                }
            }
        );
    }

    private static function resolveDocumentList(
        App $utopia,
        Database $dbForProject,
        string $databaseId,
        string $collectionId,
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $dbForProject, $databaseId, $collectionId, $type, $args) {
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);
                $swoole = $request->getSwoole();
                $swoole->post = [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'limit' => $args['limit'],
                    'offset' => $args['offset'],
                    'cursor' => $args['cursor'],
                    'cursorDirection' => $args['cursorDirection'],
                    'orderAttributes' => $args['orderAttributes'],
                    'orderType' => $args['orderType'],
                ];
                $swoole->server['request_method'] = 'GET';
                $swoole->server['request_uri'] = "/v1/databases/$databaseId/collections/$collectionId/documents";
                $swoole->server['path_info'] = "/v1/databases/$databaseId/collections/$collectionId/documents";

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    private static function resolveDocumentMutate(
        App $utopia,
        Database $dbForProject,
        string $databaseId,
        string $collectionId,
        string $method,
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $dbForProject, $databaseId, $collectionId, $method, $type, $args) {
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);
                $swoole = $request->getSwoole();

                $id = $args['id'] ?? 'unique()';
                $read = $args['read'];
                $write = $args['write'];

                unset($args['id']);
                unset($args['read']);
                unset($args['write']);

                // Order must be the same as the route params
                $swoole->post = [
                    'databaseId' => $databaseId,
                    'documentId' => $id,
                    'collectionId' => $collectionId,
                    'data' => $args,
                    'read' => $read,
                    'write' => $write,
                ];
                $swoole->server['request_method'] = $method;
                $swoole->server['request_uri'] = "/v1/databases/$databaseId/collections/$collectionId/documents";
                $swoole->server['path_info'] = "/v1/databases/$databaseId/collections/$collectionId/documents";

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    private static function resolveDocumentDelete(
        App $utopia,
        Database $dbForProject,
        string $databaseId,
        string $collectionId
    ): callable {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $dbForProject, $databaseId, $collectionId, $type, $args) {
                $request = $utopia->getResource('request', true);
                $response = $utopia->getResource('response', true);
                $swoole = $request->getSwoole();
                $swoole->post = [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => $args['id'],
                ];
                $swoole->server['request_method'] = 'DELETE';
                $swoole->server['request_uri'] = "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['id']}";
                $swoole->server['path_info'] = "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['id']}";

                self::resolve($utopia, $request, $response, $resolve, $reject);
            }
        );
    }

    /**
     * @param App $utopia
     * @param callable $resolve
     * @param callable $reject
     * @return void
     * @throws \Exception
     */
    private static function resolve(
        App $utopia,
        Request $request,
        Response $response,
        callable $resolve,
        callable $reject,
    ): void {
        $request = $utopia->getResource('request');
        $response = $utopia->getResource('response');
        $swoole = $request->getSwoole();

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
            // Then get the inner route by matching the mutated request
            $route = $utopia->setRoute(null)->match($request);

            $utopia->execute($route, $request);
        } catch (\Throwable $e) {
            self::reassign($gqlResponse, $apiResponse);
            $reject($e);
            return;
        }

        self::reassign($gqlResponse, $apiResponse);

        $payload = $apiResponse->getPayload();

        if (\array_key_exists('$id', $payload)) {
            $payload['_id'] = $payload['$id'];
        }

        if ($apiResponse->getStatusCode() < 200 || $apiResponse->getStatusCode() >= 400) {
            $reject(new GQLException($payload['message'], $apiResponse->getStatusCode()));
            return;
        }

        $resolve($payload);
    }

    /**
     * @param Response $gqlResponse
     * @param Response $apiResponse
     * @return void
     * @throws \Utopia\Exception
     */
    private static function reassign(Response $gqlResponse, Response $apiResponse): void
    {
        $gqlResponse->setContentType($apiResponse->getContentType());
        $gqlResponse->setStatusCode($apiResponse->getStatusCode());
        foreach ($apiResponse->getHeaders() as $key => $value) {
            $gqlResponse->addHeader($key, $value);
        }
        foreach ($apiResponse->getCookies() as $name => $cookie) {
            $gqlResponse->addCookie($name, $cookie['value'], $cookie['expire'], $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
    }

    /**
     * @throws \Exception
     */
    private static function applyChange(array $collectionSchema, array $change): array
    {
        $collectionId = $change['data']['collectionId'];
        $get = $collectionSchema['query'][$collectionId . 'Get'];
        $list = $collectionSchema['query'][$collectionId . 'List'];
        $create = $collectionSchema['mutation'][$collectionId . 'Create'];
        $update = $collectionSchema['mutation'][$collectionId . 'Update'];
        $delete = $collectionSchema['mutation'][$collectionId . 'Delete'];

        switch ($change['type']) {
            case 'create':
                $collectionSchema['query'][$collectionId . 'Get'] = self::addAttribute($get, $change['data']);
                $collectionSchema['query'][$collectionId . 'List'] = self::addAttribute($list, $change['data']);
                $collectionSchema['mutation'][$collectionId . 'Create'] = self::addAttribute($create, $change['data']);
                $collectionSchema['mutation'][$collectionId . 'Update'] = self::addAttribute($update, $change['data']);
                $collectionSchema['mutation'][$collectionId . 'Delete'] = self::addAttribute($delete, $change['data']);
                break;
            case 'delete':
                $collectionSchema['query'][$collectionId . 'Get'] = self::removeAttribute($get, $change['data']);
                $collectionSchema['query'][$collectionId . 'List'] = self::removeAttribute($list, $change['data']);
                $collectionSchema['mutation'][$collectionId . 'Create'] = self::removeAttribute($create, $change['data']);
                $collectionSchema['mutation'][$collectionId . 'Update'] = self::removeAttribute($update, $change['data']);
                $collectionSchema['mutation'][$collectionId . 'Delete'] = self::removeAttribute($delete, $change['data']);
                break;
            default:
                throw new \Exception('Unknown change type');
        }

        return $collectionSchema;
    }

    /**
     * @param mixed $root
     * @param array $attribute
     * @return array
     * @throws \Exception
     */
    private static function addAttribute(array $root, array $attribute): array
    {
        $databaseId = $attribute['databaseId'];
        $collectionId = $attribute['collectionId'];
        $key = $attribute['key'];
        $type = $attribute['type'];
        $array = $attribute['array'];
        $required = $attribute['required'];
        $escapedKey = str_replace('$', '_', $key);

        /** @var ObjectType $rootType */
        $rootType = $root['type'];
        $rootFields = $rootType->config['fields'];
        $rootFields[$escapedKey] = [
            'type' => self::getAttributeType($type, $array, $required),
        ];
        $root['type'] = new ObjectType([
            'name' => $collectionId,
            'fields' => $rootFields,
        ]);

        return $root;
    }

    /**
     * @param array $root
     * @param array $attribute
     * @return array
     */
    private static function removeAttribute(array $root, array $attribute): array
    {
        $databaseId = $attribute['databaseId'];
        $collectionId = $attribute['collectionId'];
        $key = $attribute['key'];
        $escapedKey = str_replace('$', '_', $key);

        /** @var ObjectType $rootType */
        $rootType = $root['type'];
        $rootFields = $rootType->config['fields'];

        unset($rootFields[$escapedKey]);

        $root['type'] = new ObjectType([
            'name' => $collectionId,
            'fields' => $rootFields,
        ]);

        return $root;
    }
}
