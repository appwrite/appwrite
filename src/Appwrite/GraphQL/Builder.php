<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\JsonType;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\User;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
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

use function \Co\go;

class Builder
{
    protected static ?JsonType $jsonParser = null;

    protected static array $typeMapping = [];

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
        App                $utopia,
        Validator|callable $validator,
        bool               $required,
        array              $injections
    ): Type
    {
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
                $nested = (fn() => $this->validator)->bindTo($validator, $validator)();
                $type = Type::listOf(self::getParameterArgType($utopia, $nested, $required, $injections));
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
     * Appends queries and mutations for the currently requested
     * projects collections to the base API GraphQL schema.
     *
     * @param array $apiSchema
     * @param App $utopia
     * @param Request $request
     * @param Response $response
     * @param Database $dbForProject
     * @return Schema
     * @throws \Exception
     */
    public static function appendProjectSchema(
        array    $apiSchema,
        App      $utopia,
        Request  $request,
        Response $response,
        Database $dbForProject
    ): Schema
    {
        $db = self::buildCollectionsSchema($utopia, $request, $response, $dbForProject);

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
     * @throws \Exception
     */
    public static function buildSchema(
        App      $utopia,
        Request  $request,
        Response $response,
        Registry &$register,
        Database $dbForProject,
        Document $user,
    ): Schema
    {
        $apiSchema = self::buildAPISchema($utopia, $request, $response, $register);
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
     * This function iterates all a projects attributes and builds
     * GraphQL queries and mutations for the collections they make up.
     *
     * @param App $utopia
     * @param Request $request
     * @param Response $response
     * @param Database $dbForProject
     * @return array
     * @throws \Exception
     */
    public static function buildCollectionsSchema(
        App       $utopia,
        Request   $request,
        Response  $response,
        Database  $dbForProject,
        ?Document $user = null,
    ): array
    {
        $start = microtime(true);

        $userId = $user?->getId();
        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 1000;
        $offset = 0;
        $wg = new WaitGroup();

        while (!empty($attrs = Authorization::skip(fn() => $dbForProject->find(
            'attributes',
            limit: $limit,
            offset: $offset
        )))) {
            $wg->add();
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
                        'resolve' => fn($object, $args, $context, $info) => $object->then(function ($obj) use ($key) {
                            return $obj['result'][$key];
                        }),
                    ];
                }

                foreach ($collections as $collectionId => $attributes) {
                    $objectType = new ObjectType([
                        'name' => $collectionId,
                        'fields' => $attributes
                    ]);
                    $idArgs = [
                        'id' => [
                            'type' => Type::string()
                        ]
                    ];
                    $listArgs = [
                        'limit' => [
                            'type' => Type::int(),
                            'defaultValue' => $limit,
                        ],
                        'offset' => [
                            'type' => Type::int(),
                            'defaultValue' => 0,
                        ],
                        'cursor' => [
                            'type' => Type::string(),
                            'defaultValue' => null,
                        ],
                        'orderAttributes' => [
                            'type' => Type::listOf(Type::string()),
                            'defaultValue' => [],
                        ],
                        'orderType' => [
                            'type' => Type::listOf(Type::string()),
                            'defaultValue' => [],
                        ]
                    ];

                    $attributes['read'] = [
                        'type' => Type::listOf(Type::string()),
                        'defaultValue' => ["user:$userId"],
                        'resolve' => function ($object, $args, $context, $info) use ($collectionId) {
                            return $object->getAttribute('$read');
                        }
                    ];
                    $attributes['write'] = [
                        'type' => Type::listOf(Type::string()),
                        'defaultValue' => ["user:$userId"],
                        'resolve' => function ($object, $args, $context, $info) use ($collectionId) {
                            return $object->getAttribute('$write');
                        }
                    ];

                    $queryFields[$collectionId . 'Get'] = [
                        'type' => $objectType,
                        'args' => $idArgs,
                        'resolve' => self::queryGet($utopia, $request, $response, $dbForProject, $collectionId)
                    ];
                    $queryFields[$collectionId . 'List'] = [
                        'type' => $objectType,
                        'args' => $listArgs,
                        'resolve' => self::queryList($collectionId, $dbForProject)
                    ];
                    $mutationFields[$collectionId . 'Create'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => self::mutateCreate($collectionId, $dbForProject)
                    ];
                    $mutationFields[$collectionId . 'Update'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => self::mutateUpdate($collectionId, $dbForProject)
                    ];
                    $mutationFields[$collectionId . 'Delete'] = [
                        'type' => $objectType,
                        'args' => $idArgs,
                        'resolve' => self::mutateDelete($collectionId, $dbForProject)
                    ];
                }
                $wg->done();
            });
            $offset += $limit;
        }
        $wg->wait();
        $time_elapsed_secs = (microtime(true) - $start) * 1000;
        Console::info("[INFO] Built GraphQL Project Collection Schema in ${time_elapsed_secs}ms");

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    private static function queryGet(
        App      $utopia,
        Request  $request,
        Response $response,
        Database $dbForProject,
        string   $collectionId
    ): callable
    {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($utopia, $request, $response, $dbForProject, $collectionId, $type, $args) {
                try {
                    $swooleRq = $request->getSwoole();
                    $swooleRq->post = [
                        'collectionId' => $collectionId,
                        'documentId' => $args['id'],
                    ];
                    // Drop json content type so post args are used directly
                    if (\array_key_exists('content-type', $swooleRq->header)
                        && $swooleRq->header['content-type'] === 'application/json') {
                        unset($swooleRq->header['content-type']);
                    }
                    $request = new Request($swooleRq);
                    $url = '/v1/database/collections/:collectionId/documents/:documentId';
                    $route = $utopia->getRoutes()['GET'][$url];
                    $utopia
                        ->setRoute($route)
                        ->execute($route, $request);

                    $resolve($response->getPayload());
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
    }

    private static function queryList(string $collectionId, Database $dbForProject): callable
    {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $list = $dbForProject->find($collectionId);
                    $resolve($list);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
    }

    private static function mutateCreate(string $collectionId, Database $dbForProject): callable
    {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->createDocument($collectionId, new Document($args)));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
    }

    private static function mutateUpdate(string $collectionId, Database $dbForProject): callable
    {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->updateDocument($collectionId, $args['id'], new Document($args)));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
    }

    private static function mutateDelete(string $collectionId, Database $dbForProject): callable
    {
        return fn($type, $args, $context, $info) => new CoroutinePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->deleteDocument($collectionId, $args['id']));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
    }

    /**
     * This function goes through all the REST endpoints in the API and builds a
     * GraphQL schema for all those routes whose response model is neither empty nor NONE
     *
     * @param App $utopia
     * @param Request $request
     * @param Response $response
     * @param Registry $register
     * @return array
     * @throws \Exception
     */
    public static function buildAPISchema(App $utopia, Request $request, Response $response, Registry $register): array
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

                    $resolve = fn($type, $args, $context, $info) => new CoroutinePromise(
                        function (callable $resolve, callable $reject) use ($utopia, $request, $response, &$register, $route, $args, $context, $info) {
                            // Mutate the original request object to include the query variables at the top level
                            $swoole = $request->getSwoole();
                            $swoole->post = $args;

                            // Drop json content type so post args are used directly
                            if (\array_key_exists('content-type', $swoole->header)
                                && $swoole->header['content-type'] === 'application/json') {
                                unset($swoole->header['content-type']);
                            }
                            $request = new Request($swoole);
                            $response = new Response($response->getSwoole());

                            $utopia->setResource('request', fn() => $request);
                            $utopia->setResource('response', fn() => $response);

                            $response->setContentType(Response::CONTENT_TYPE_NULL);
                            $utopia->setRoute($route)->execute($route, $request);

                            $result = $response->getPayload();

                            if ($response->getCurrentModel() == Response::MODEL_ERROR_DEV) {
                                $reject(new GQLExceptionDev($result['message'], $result['code'], $result['version'], $result['file'], $result['line'], $result['trace']));
                            } else if ($response->getCurrentModel() == Response::MODEL_ERROR) {
                                $reject(new GQLException($result['message'], $result['code']));
                            }

                            $resolve($result);
                        }
                    );

                    $field = [
                        'type' => $type,
                        'description' => $description,
                        'args' => $args,
                        'resolve' => $resolve
                    ];

                    if ($method == 'GET') {
                        $queryFields[$methodName] = $field;
                    } else if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH' || $method == 'DELETE') {
                        $mutationFields[$methodName] = $field;
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
     * Function to create an appropriate GraphQL Error Formatter
     * Based on whether we're on a development build or production
     * build of Appwrite.
     *
     * @param bool $isDevelopment
     * @param string $version
     * @return callable
     */
    public static function getErrorFormatter(bool $isDevelopment, string $version): callable
    {
        return function (Error $error) use ($isDevelopment, $version) {
            $formattedError = FormattedError::createFromException($error);

            // Previous error represents the actual error thrown by Appwrite server
            $previousError = $error->getPrevious() ?? $error;
            $formattedError['code'] = $previousError->getCode();
            $formattedError['version'] = $version;
            if ($isDevelopment) {
                $formattedError['file'] = $previousError->getFile();
                $formattedError['line'] = $previousError->getLine();
            }
            return $formattedError;
        };
    }
}
