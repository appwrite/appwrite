<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\JsonType;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
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
    public static function json()
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
            $keyWithoutSpecialChars = str_replace('$', '_', $key);
            if (isset(self::$typeMapping[$props['type']])) {
                $type = self::$typeMapping[$props['type']];
            } else {
                try {
                    $complexModel = $response->getModel($props['type']);
                    $type = self::getTypeMapping($complexModel, $response);
                } catch (\Exception $e) {
                    Console::error("Could Not find model for : {$props['type']}");
                }
            }
            if ($props['array']) {
                $type = Type::listOf($type);
            }
            $fields[$keyWithoutSpecialChars] = [
                'type' => $type,
                'description' => $props['description'],
                'resolve' => function ($object, $args, $context, $info) use ($key) {
                    return $object[$key];
                }
            ];
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
     * @return GraphQL\Type\Definition\Type
     */
    protected static function getArgType($validator, bool $required, $utopia, $injections): Type
    {
        $validator = (\is_callable($validator)) ? call_user_func_array($validator, $utopia->getResources($injections)) : $validator;
        $type = [];
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
                $type = Type::listOf(self::json());
                break;
            case 'Utopia\Validator\Numeric':
            case 'Utopia\Validator\Range':
                $type = Type::int();
                break;
            case 'Utopia\Validator\Assoc':
            default:
                $type = self::json();
                break;
        }

        if ($required) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    public static function appendSchema($schema, $dbForProject): Schema
    {
        Console::log("[INFO] Appending GraphQL Database Schema...");
        $start = microtime(true);

        $db = self::buildCollectionsSchema($dbForProject);

        $queryFields = $schema->getQueryType()?->getFields() ?? [];
        $mutationFields = $schema->getMutationType()?->getFields() ?? [];

        $queryFields = \array_merge($queryFields, $db['query']);
        $mutationFields = \array_merge($mutationFields, $db['mutation']);

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
        Console::log("[INFO] Time Taken To Append Database to API Schema : ${time_elapsed_secs}s");

        return $schema;
    }

    /**
     * @throws \Exception
     */
    public static function buildSchema($utopia, $response, $register, $dbForProject): Schema
    {
        $db = self::buildCollectionsSchema($dbForProject, $register);
        $api = self::buildAPISchema($utopia, $response, $register);

        $queryFields = \array_merge($api['query'], $db['query']);
        $mutationFields = \array_merge($api['mutation'], $db['mutation']);

        ksort($queryFields);
        ksort($mutationFields);

        return new Schema([
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
    }

    /**
     * This function goes through all the project attributes and builds a
     * GraphQL schema for all the collections they make up.
     *
     * @param Database $dbForProject
     * @return array
     * @throws \Exception
     */
    public static function buildCollectionsSchema(Database $dbForProject, Registry &$register): array
    {
        Console::log("[INFO] Building GraphQL Database Schema...");
        $start = microtime(true);

        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $offset = 0;

        Authorization::skip(function () use ($mutationFields, $queryFields, $collections, $register, $offset, $dbForProject) {
            while (!empty($attrs = $dbForProject->find(
                'attributes',
                limit: $dbForProject->getAttributeLimit(),
                offset: $offset
            ))) {
                go(function ($attrs, $dbForProject, $register, $collections, $queryFields, $mutationFields) {
                    foreach ($attrs as $attr) {
                        go(function ($attr, &$collections) {
                            /** @var Document $attr */

                            $collectionId = $attr->getAttribute('collectionId');

                            if (isset(self::$typeMapping[$collectionId])) {
                                return;
                            }
                            if ($attr->getAttribute('status') !== 'available') {
                                return;
                            }

                            $key = $attr->getAttribute('key');
                            $type = $attr->getAttribute('type');

                            $escapedKey = str_replace('$', '_', $key);

                            $collections[$collectionId][$escapedKey] = [
                                'type' => $type,
                                'resolve' => function ($object, $args, $context, $info) use ($key) {
                                    return $object->getAttribute($key);
                                }
                            ];

                        }, $attr, $collections);
                    }

                    foreach ($collections as $collectionId => $attributes) {
                        go(function ($collectionId, $attributes, $dbForProject, $register, &$queryFields, &$mutationFields) {
                            if (isset(self::$typeMapping[$collectionId])) {
                                return;
                            }

                            $objectType = new ObjectType([
                                'name' => \ucfirst($collectionId),
                                'fields' => $attributes
                            ]);

                            self::$typeMapping[$collectionId] = $objectType;

                            $mutateArgs = [];

                            foreach ($attributes as $name => $attribute) {
                                $mutateArgs[$name] = [
                                    'type' => $attribute['type']
                                ];
                            }

                            $idArgs = [
                                'id' => [
                                    'type' => Type::string()
                                ]
                            ];

                            $listArgs = [
                                'limit' => [
                                    'type' => Type::int()
                                ],
                                'offset' => [
                                    'type' => Type::int()
                                ],
                                'cursor' => [
                                    'type' => Type::string()
                                ],
                                'orderAttributes' => [
                                    'type' => Type::listOf(Type::string())
                                ],
                                'orderType' => [
                                    'types' => Type::listOf(Type::string())
                                ]
                            ];

                            self::createCollectionGetQuery($collectionId, $register, $dbForProject, $idArgs, $queryFields);
                            self::createCollectionListQuery($collectionId, $register, $dbForProject, $listArgs, $queryFields);
                            self::createCollectionCreateMutation($collectionId, $register, $dbForProject, $mutateArgs, $mutationFields);
                            self::createCollectionUpdateMutation($collectionId, $register, $dbForProject, $mutateArgs, $mutationFields);
                            self::createCollectionDeleteMutation($collectionId, $register, $dbForProject, $idArgs, $mutationFields);

                        }, $collectionId, $attributes, $dbForProject, $register, $queryFields, $mutationFields);
                    }
                }, $attrs, $dbForProject, $register, $collections, $queryFields, $mutationFields);

                $offset += $dbForProject->getAttributeLimit();
            }
        });

        $time_elapsed_secs = microtime(true) - $start;
        Console::log("[INFO] Time Taken To Build Database Schema : ${time_elapsed_secs}s");
        Console::info('[INFO] Schema : ' . json_encode([
                'query' => $queryFields,
                'mutation' => $mutationFields
            ]));

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    private static function createCollectionGetQuery($collectionId, $register, $dbForProject, $args, &$queryFields)
    {
        $resolve = function ($type, $args, $context, $info) use ($collectionId, &$register, $dbForProject) {
            return SwoolePromise::create(function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->getDocument($collectionId, $args['id']));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        };
        $get = [
            'type' => \ucfirst($collectionId),
            'args' => $args,
            'resolve' => $resolve
        ];
        $queryFields['get' . \ucfirst($collectionId)] = $get;
    }

    private static function createCollectionListQuery($collectionId, $register, $dbForProject, $args, &$queryFields)
    {
        $resolve = function ($type, $args, $context, $info) use ($collectionId, &$register, $dbForProject) {
            return SwoolePromise::create(function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->getCollection($collectionId));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        };
        $list = [
            'type' => \ucfirst($collectionId),
            'args' => $args,
            'resolve' => $resolve
        ];
        $queryFields['list' . \ucfirst($collectionId)] = $list;
    }

    private static function createCollectionCreateMutation($collectionId, $register, $dbForProject, $args, &$mutationFields)
    {
        $resolve = function ($type, $args, $context, $info) use ($collectionId, &$register, $dbForProject) {
            return SwoolePromise::create(function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->createDocument($collectionId, new Document($args)));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        };
        $create = [
            'type' => \ucfirst($collectionId),
            'args' => $args,
            'resolve' => $resolve
        ];
        $mutationFields['create' . \ucfirst($collectionId)] = $create;
    }

    private static function createCollectionUpdateMutation($collectionId, $register, $dbForProject, $args, &$mutationFields)
    {
        $resolve = function ($type, $args, $context, $info) use ($collectionId, &$register, $dbForProject) {
            return SwoolePromise::create(function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->updateDocument($collectionId, $args['id'], new Document($args)));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        };

        $update = [
            'type' => \ucfirst($collectionId),
            'args' => $args,
            'resolve' => $resolve
        ];

        $mutationFields['update' . \ucfirst($collectionId)] = $update;
    }


    private static function createCollectionDeleteMutation($collectionId, $register, $dbForProject, $args, &$mutationFields)
    {
        $resolve = function ($type, $args, $context, $info) use ($collectionId, &$register, $dbForProject) {
            return SwoolePromise::create(function (callable $resolve, callable $reject) use ($collectionId, $type, $args, $dbForProject) {
                try {
                    $resolve($dbForProject->deleteDocument($collectionId, $args['id']));
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        };
        $delete = [
            'type' => \ucfirst($collectionId),
            'args' => $args,
            'resolve' => $resolve
        ];
        $mutationFields['delete' . \ucfirst($collectionId)] = $delete;
    }

    /**
     * This function goes through all the REST endpoints in the API and builds a
     * GraphQL schema for all those routes whose response model is neither empty nor NONE
     *
     * @param $utopia
     * @param $response
     * @param $register
     * @return array
     */
    public static function buildAPISchema($utopia, $response, $register): array
    {
        Console::log("[INFO] Building GraphQL API Schema...");
        $start = microtime(true);

        self::init();
        $queryFields = [];
        $mutationFields = [];

        foreach ($utopia->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $namespace = $route->getLabel('sdk.namespace', '');
                $methodName = $namespace . \ucfirst($route->getLabel('sdk.method', ''));
                $responseModelName = $route->getLabel('sdk.response.model', "none");

                Console::info("Namespace: $namespace");
                Console::info("Method: $methodName");
                Console::info("Response Model: $responseModelName");
                Console::info("Raw routes: " . \json_encode($routes));
                Console::info("Raw route: " . \json_encode($route));

                if ($responseModelName !== "none") {
                    $responseModel = $response->getModel($responseModelName);

                    /* Create a GraphQL type for the current response model */
                    $type = self::getTypeMapping($responseModel, $response);
                    /* Get a description for this type */
                    $description = $route->getDesc();
                    /* Create the args required for this type */
                    $args = [];
                    foreach ($route->getParams() as $key => $value) {
                        $args[$key] = [
                            'type' => self::getArgType($value['validator'], !$value['optional'], $utopia, $value['injections']),
                            'description' => $value['description'],
                            'defaultValue' => $value['default']
                        ];
                    }
                    /* Define a resolve function that defines how to fetch data for this type */
                    $resolve = function ($type, $args, $context, $info) use (&$register, $route) {
                        return SwoolePromise::create(function (callable $resolve, callable $reject) use (&$register, $route, $args) {
                            $utopia = $register->get('__app');
                            $utopia->setRoute($route)->execute($route, $args);

                            $response = $register->get('__response');
                            $result = $response->getPayload();

                            if ($response->getCurrentModel() == Response::MODEL_ERROR_DEV) {
                                $reject(new ExceptionDev($result['message'], $result['code'], $result['version'], $result['file'], $result['line'], $result['trace']));
                            } else if ($response->getCurrentModel() == Response::MODEL_ERROR) {
                                $reject(new \Exception($result['message'], $result['code']));
                            }

                            $resolve($result);
                        });
                    };

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

        $time_elapsed_secs = microtime(true) - $start;
        Console::log("[INFO] Time Taken To Build API Schema : ${time_elapsed_secs}s");

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
