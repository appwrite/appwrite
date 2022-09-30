<?php

namespace Appwrite\GraphQL;

use Appwrite\Utopia\Response;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Swoole\Coroutine\WaitGroup;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;
use Utopia\Route;

class SchemaBuilder
{
    /**
     * @throws \Exception
     */
    public static function buildSchema(
        App $utopia,
        string $projectId,
        Database $dbForProject
    ): Schema {
        App::setResource('current', static fn() => $utopia);

        /** @var Registry $register */
        $register = $utopia->getResource('register');
        $appVersion = App::getEnv('_APP_VERSION');

        $apiSchemaKey = 'apiSchema';
        $apiVersionKey = 'apiSchemaVersion';
        $collectionSchemaKey = $projectId . 'CollectionSchema';
        $collectionsDirtyKey = $projectId . 'SchemaDirty';
        $fullSchemaKey = $projectId . 'FullSchema';

        $schemaVersion = $register->has($apiVersionKey) ? $register->get($apiVersionKey) : '';
        $collectionSchemaDirty = $register->has($collectionsDirtyKey) ? $register->get($collectionsDirtyKey) : true;
        $apiSchemaDirty = \version_compare($appVersion, $schemaVersion, "!=");

        if (
            !$collectionSchemaDirty
            && !$apiSchemaDirty
            && $register->has($fullSchemaKey)
        ) {
            return $register->get($fullSchemaKey);
        }

        if ($register->has($apiSchemaKey) && !$apiSchemaDirty) {
            $apiSchema = $register->get($apiSchemaKey);
        } else {
            $apiSchema = &self::buildAPISchema($utopia);
            $register->set($apiSchemaKey, static function &() use (&$apiSchema) {
                return $apiSchema;
            });
            $register->set($apiVersionKey, static fn() => $appVersion);
        }

        if ($register->has($collectionSchemaKey) && !$collectionSchemaDirty) {
            $collectionSchema = $register->get($collectionSchemaKey);
        } else {
            $collectionSchema = &self::buildCollectionSchema($utopia, $dbForProject);
            $register->set($collectionSchemaKey, static function &() use (&$collectionSchema) {
                return $collectionSchema;
            });
            $register->set($collectionsDirtyKey, static fn() => false);
        }

        $queryFields = \array_merge_recursive(
            $apiSchema['query'],
            $collectionSchema['query']
        );
        $mutationFields = \array_merge_recursive(
            $apiSchema['mutation'],
            $collectionSchema['mutation']
        );

        \ksort($queryFields);
        \ksort($mutationFields);

        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => $queryFields
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutationFields
            ])
        ]);

        $register->set($fullSchemaKey, static fn() => $schema);

        return $schema;
    }

    /**
     * This function iterates all API routes and builds a GraphQL
     * schema defining types and resolvers for all response models.
     *
     * @param App $utopia
     * @return array
     * @throws \Exception
     */
    public static function &buildAPISchema(App $utopia): array
    {
        $models = $utopia
            ->getResource('response')
            ->getModels();

        TypeMapper::init($models);

        $queries = [];
        $mutations = [];

        foreach (App::getRoutes() as $type => $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                $namespace = $route->getLabel('sdk.namespace', '');
                $method = $route->getLabel('sdk.method', '');
                $name = $namespace . \ucfirst($method);

                if (empty($name)) {
                    continue;
                }

                foreach (TypeMapper::fromRoute($utopia, $route) as $field) {
                    switch ($route->getMethod()) {
                        case 'GET':
                            $queries[$name] = $field;
                            break;
                        case 'POST':
                        case 'PUT':
                        case 'PATCH':
                        case 'DELETE':
                            $mutations[$name] = $field;
                            break;
                        default:
                            throw new \Exception("Unsupported method: {$route->getMethod()}");
                    }
                }
            }
        }

        $schema = [
            'query' => $queries,
            'mutation' => $mutations
        ];

        return $schema;
    }

    /**
     * Iterates all of a projects attributes and builds GraphQL
     * queries and mutations for the collections they make up.
     *
     * @param App $utopia
     * @param Database $dbForProject
     * @return array
     * @throws \Exception
     */
    public static function &buildCollectionSchema(
        App $utopia,
        Database $dbForProject
    ): array {
        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 1000;
        $offset = 0;
        $count = 0;

        $wg = new WaitGroup();

        while (
            !empty($attrs = Authorization::skip(fn() => $dbForProject->find('attributes', [
            Query::limit($limit),
            Query::offset($offset),
            ])))
        ) {
            $wg->add();
            $count += count($attrs);
            \go(function () use ($utopia, $dbForProject, &$collections, &$queryFields, &$mutationFields, $limit, &$offset, $attrs, $wg) {
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
                    $default = $attr->getAttribute('default');
                    $escapedKey = str_replace('$', '_', $key);
                    $collections[$collectionId][$escapedKey] = [
                        'type' => TypeMapper::fromCollectionAttribute(
                            $type,
                            $array,
                            $required
                        ),
                        'defaultValue' => $default,
                    ];
                }

                foreach ($collections as $collectionId => $attributes) {
                    $objectType = new ObjectType([
                        'name' => $collectionId,
                        'fields' => \array_merge(
                            ["_id" => ['type' => Type::string()]],
                            $attributes
                        ),
                    ]);
                    $attributes = \array_merge(
                        $attributes,
                        TypeMapper::argumentsFor('mutate')
                    );

                    $queryFields[$collectionId . 'Get'] = [
                        'type' => $objectType,
                        'args' => TypeMapper::argumentsFor('id'),
                        'resolve' => Resolvers::resolveDocumentGet(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId
                        )
                    ];
                    $queryFields[$collectionId . 'List'] = [
                        'type' => Type::listOf($objectType),
                        'args' => TypeMapper::argumentsFor('list'),
                        'resolve' => Resolvers::resolveDocumentList(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId
                        ),
                        'complexity' => function (int $complexity, array $args) {
                            $queries = Query::parseQueries($args['queries'] ?? []);
                            $query = Query::getByType($queries, Query::TYPE_LIMIT)[0] ?? null;
                            $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

                            return $complexity * $limit;
                        },
                    ];

                    $mutationFields[$collectionId . 'Create'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => Resolvers::resolveDocumentCreate(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId,
                        )
                    ];
                    $mutationFields[$collectionId . 'Update'] = [
                        'type' => $objectType,
                        'args' => \array_merge(
                            TypeMapper::argumentsFor('id'),
                            \array_map(
                                fn($attr) => $attr['type'] = Type::getNullableType($attr['type']),
                                $attributes
                            )
                        ),
                        'resolve' => Resolvers::resolveDocumentUpdate(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId,
                        )
                    ];
                    $mutationFields[$collectionId . 'Delete'] = [
                        'type' => TypeMapper::fromResponseModel(Response::MODEL_NONE),
                        'args' => TypeMapper::argumentsFor('id'),
                        'resolve' => Resolvers::resolveDocumentDelete(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId
                        )
                    ];
                }
                $wg->done();
            });
            $offset += $limit;
        }
        $wg->wait();

        $schema = [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];

        return $schema;
    }
}
