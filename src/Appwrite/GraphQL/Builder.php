<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\JsonType;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;

class Builder
{
    protected static ?JsonType $jsonParser = null;

    protected static array $typeMapping = [];

    /**
     * Function to initialise the typeMapping array with the base cases of the recursion
     *
     * @return   void
     */
    public static function init()
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
     * Function to create a singleton for $jsonParser
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
     * If the map already contains the type, end the recursion and return.
     * Iterate through all the rules in the response model. Each rule is of the form
     *        [
     *            [KEY 1] => [
     *                'type' => A string from Appwrite/Utopia/Response
     *                'description' => A description of the type
     *                'default' => A default value for this type
     *                'example' => An example of this type
     *                'require' => a boolean representing whether this field is required
     *                'array' => a boolean representing whether this field is an array
     *            ],
     *            [KEY 2] => [
     *            ],
     *            [KEY 3] => [
     *            ] .....
     *        ]
     *   If there are any field names containing characters other than a-z, A-Z, 0-9, _ ,
     *   we need to remove all those characters. Currently Appwrite's Response model has only the
     *   $ sign which is prohibited by the GraphQL spec. So we're only replacing that. We need to replace this with a regex
     *   based approach.
     *
     * @param Model $model
     * @param Response $response
     * @return Type
     */
    static function getTypeMapping(Model $model, Response $response): Type
    {
        if (isset(self::$typeMapping[$model->getType()])) {
            return self::$typeMapping[$model->getType()];
        }

        $rules = $model->getRules();
        $name = $model->getType();
        $fields = [];
        $type = null;

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
                        $type = self::getTypeMapping($complexModel, $response);
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
                    'resolve' => function ($object, $args, $context, $info) use ($key) {
                        return $object[$key];
                    }
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
     * Function to map a Utopia\Validator to a valid GraphQL Type
     *
     * @param $validator
     * @param bool $required
     * @param $utopia
     * @param $injections
     * @return Type
     * @throws \Exception
     */
    private static function getParameterArgType($validator, bool $required, $utopia, $injections): Type
    {
        $validator = \is_callable($validator)
            ? \call_user_func_array($validator, $utopia->getResources($injections))
            : $validator;

        switch ((!empty($validator)) ? \get_class($validator) : '') {
            case 'Utopia\Validator\Email':
            case 'Utopia\Validator\Host':
            case 'Utopia\Validator\Length':
            case 'Appwrite\Auth\Validator\Password':
            case 'Utopia\Validator\URL':
            case 'Appwrite\Database\Validator\UID':
            case 'Appwrite\Storage\Validator\File':
            case 'Utopia\Validator\WhiteList':
            case 'Utopia\Validator\Text':
                $type = Type::string();
                break;
            case 'Utopia\Validator\Boolean':
                $type = Type::boolean();
                break;
            case 'Utopia\Validator\ArrayList':
                $nested = (fn() => $this->validator)->bindTo($validator, $validator)();
                $type = Type::listOf(self::getParameterArgType($nested, $required, $utopia, $injections));
                break;
            case 'Utopia\Validator\Numeric':
            case 'Utopia\Validator\Range':
                $type = Type::int();
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
     * Function to map an attribute type to a valid GraphQL Type
     *
     * @param $validator
     * @param bool $required
     * @param $utopia
     * @param $injections
     * @return Type
     * @throws \Exception
     */
    private static function getAttributeArgType($type, $array, $required): Type
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
    public static function appendProjectSchema(
        array    $apiSchema,
        Registry $register,
        Database $dbForProject
    ): Schema
    {
        Console::info("[INFO] Merging Schema...");

        $start = microtime(true);

        $db = self::buildCollectionsSchema($register, $dbForProject);

        $queryFields = \array_merge($apiSchema['query'], $db['query']);
        $mutationFields = \array_merge($apiSchema['mutation'], $db['mutation']);

        ksort($queryFields);
        ksort($mutationFields);

        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'description' => 'The root of all queries',
                'fields' => $queryFields
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'description' => 'The root of all mutations',
                'fields' => $mutationFields
            ])
        ]);

        $time_elapsed_secs = microtime(true) - $start;

        Console::info("[INFO] Time Taken To Merge Schema : ${time_elapsed_secs}s");

        return $schema;
    }

    /**
     * This function goes through all the project attributes and builds a
     * GraphQL schema for all the collections they make up.
     *
     * @param Registry $register
     * @param Database $dbForProject
     * @return array
     * @throws \Exception
     */
    public static function buildCollectionsSchema(Registry &$register, Database $dbForProject): array
    {
        Console::info("[INFO] Building GraphQL Project Collection Schema...");
        $start = microtime(true);

        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 50;
        $offset = 0;

        Authorization::skip(function () use (&$mutationFields, &$queryFields, &$collections, $register, $limit, $offset, $dbForProject) {
            while (!empty($attrs = $dbForProject->find(
                'attributes',
                limit: $limit,
                offset: $offset
            ))) {
                foreach ($attrs as $attr) {
                    $collectionId = $attr->getAttribute('collectionId');

                    if ($attr->getAttribute('status') !== 'available') {
                        return;
                    }

                    $key = $attr->getAttribute('key');
                    $type = $attr->getAttribute('type');
                    $array = $attr->getAttribute('array');
                    $required = $attr->getAttribute('required');

                    $escapedKey = str_replace('$', '_', $key);

                    $collections[$collectionId][$escapedKey] = [
                        'type' => self::getAttributeArgType($type, $array, $required),
                        'resolve' => function ($object, $args, $context, $info) use ($key) {
                            return $object->getAttribute($key);
                        }
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
                            'types' => Type::listOf(Type::string()),
                            'defaultValue' => [],
                        ]
                    ];

                    self::createCollectionGetQuery($collectionId, $register, $dbForProject, $idArgs, $queryFields, $objectType);
                    self::createCollectionListQuery($collectionId, $register, $dbForProject, $listArgs, $queryFields, $objectType);
                    self::createCollectionCreateMutation($collectionId, $register, $dbForProject, $attributes, $mutationFields, $objectType);
                    self::createCollectionUpdateMutation($collectionId, $register, $dbForProject, $attributes, $mutationFields, $objectType);
                    self::createCollectionDeleteMutation($collectionId, $register, $dbForProject, $idArgs, $mutationFields, $objectType);
                }

                $offset += $limit;
            }
        });

        $time_elapsed_secs = microtime(true) - $start;
        Console::info("[INFO] Time Taken To Build Project Collection Schema : ${time_elapsed_secs}s");

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    private static function createCollectionGetQuery($collectionId, $register, $dbForProject, $args, &$queryFields, $objectType)
    {
        $resolve = fn($type, $args, $context, $info) => new SwoolePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->getDocument($collectionId, $args['id']));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
        $get = [
            'type' => $objectType,
            'args' => $args,
            'resolve' => $resolve
        ];
        $queryFields[$collectionId . 'Get'] = $get;
    }

    private static function createCollectionListQuery($collectionId, $register, $dbForProject, $args, &$queryFields, $objectType)
    {
        $resolve = fn($type, $args, $context, $info) => new SwoolePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->getCollection($collectionId));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );

        $list = [
            'type' => $objectType,
            'args' => $args,
            'resolve' => $resolve
        ];
        $queryFields[$collectionId . 'List'] = $list;
    }

    private static function createCollectionCreateMutation($collectionId, $register, $dbForProject, $args, &$mutationFields, $objectType)
    {
        $resolve = fn($type, $args, $context, $info) => new SwoolePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->createDocument($collectionId, new Document($args)));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
        $create = [
            'type' => $objectType,
            'args' => $args,
            'resolve' => $resolve
        ];
        $mutationFields[$collectionId . 'Create'] = $create;
    }

    private static function createCollectionUpdateMutation($collectionId, $register, $dbForProject, $args, &$mutationFields, $objectType)
    {
        $resolve = fn($type, $args, $context, $info) => new SwoolePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->updateDocument($collectionId, $args['id'], new Document($args)));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );

        $update = [
            'type' => $objectType,
            'args' => $args,
            'resolve' => $resolve
        ];

        $mutationFields[$collectionId . 'Update'] = $update;
    }


    private static function createCollectionDeleteMutation($collectionId, $register, $dbForProject, $args, &$mutationFields, $objectType)
    {
        $resolve = fn($type, $args, $context, $info) => new SwoolePromise(
            function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->deleteDocument($collectionId, $args['id']));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );
        $delete = [
            'type' => $objectType,
            'args' => $args,
            'resolve' => $resolve
        ];
        $mutationFields[$collectionId . 'Delete'] = $delete;
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
        Console::info("[INFO] Building GraphQL REST API Schema...");
        $start = microtime(true);

        self::init();
        $queryFields = [];
        $mutationFields = [];

        foreach ($utopia->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                if (str_starts_with($route->getPath(), '/v1/mock/')) {
                    continue;
                }
                $namespace = $route->getLabel('sdk.namespace', '');
                $methodName = $namespace . \ucfirst($route->getLabel('sdk.method', ''));
                $responseModelNames = $route->getLabel('sdk.response.model', "none");

                if ($responseModelNames !== "none") {
                    $responseModels = \is_array($responseModelNames)
                        ? \array_map(static fn($m) => $response->getModel($m), $responseModelNames)
                        : [$response->getModel($responseModelNames)];

                    foreach ($responseModels as $responseModel) {
                        $type = self::getTypeMapping($responseModel, $response);
                        $description = $route->getDesc();
                        $args = [];

                        foreach ($route->getParams() as $key => $value) {
                            $args[$key] = [
                                'type' => self::getParameterArgType(
                                    $value['validator'],
                                    !$value['optional'],
                                    $utopia,
                                    $value['injections']
                                ),
                                'description' => $value['description'],
                                'defaultValue' => $value['default']
                            ];
                        }

                        /* Define a resolve function that defines how to fetch data for this type */
                        $resolve = fn($type, $args, $context, $info) => new SwoolePromise(
                            function (callable $resolve, callable $reject) use ($utopia, $request, $response, &$register, $route, $args) {
                                $utopia
                                    ->setRoute($route)
                                    ->execute($route, $request);

                                $result = $response->getPayload();

                                if ($response->getCurrentModel() == Response::MODEL_ERROR_DEV) {
                                    $reject(new ExceptionDev($result['message'], $result['code'], $result['version'], $result['file'], $result['line'], $result['trace']));
                                } else if ($response->getCurrentModel() == Response::MODEL_ERROR) {
                                    $reject(new \Exception($result['message'], $result['code']));
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
        }

        $time_elapsed_secs = microtime(true) - $start;
        Console::info("[INFO] Time Taken To Build REST API Schema : ${time_elapsed_secs}s");

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
