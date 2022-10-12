<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\Utopia\Response;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema as GQLSchema;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Route;

class Schema
{
    protected static ?GQLSchema $schema = null;
    protected static array $dirty = [];

    /**
     * @throws \Exception
     */
    public static function build(
        App $utopia,
        string $projectId,
        Database $dbForProject
    ): GQLSchema {
        App::setResource('utopia:graphql', static function () use ($utopia) {
            return $utopia;
        });

        if (!empty(self::$schema)) {
            return self::$schema;
        }

        $api = static::api($utopia);
        //$collections = static::collections($utopia, $dbForProject);

        $queries = \array_merge_recursive(
            $api['query'],
            //$collections['query']
        );
        $mutations = \array_merge_recursive(
            $api['mutation'],
            //$collections['mutation']
        );

        \ksort($queries);
        \ksort($mutations);

        return static::$schema = new GQLSchema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => $queries
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutations
            ])
        ]);
    }

    /**
     * This function iterates all API routes and builds a GraphQL
     * schema defining types and resolvers for all response models.
     *
     * @param App $utopia
     * @return array
     * @throws \Exception
     */
    protected static function api(App $utopia): array
    {
        Mapper::init($utopia
            ->getResource('response')
            ->getModels());

        $queries = [];
        $mutations = [];

        foreach ($utopia->getRoutes() as $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                $namespace = $route->getLabel('sdk.namespace', '');
                $method = $route->getLabel('sdk.method', '');
                $name = $namespace . \ucfirst($method);

                if (empty($name)) {
                    continue;
                }

                foreach (Mapper::route($utopia, $route) as $field) {
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

        return [
            'query' => $queries,
            'mutation' => $mutations
        ];
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
    protected static function collections(
        App $utopia,
        Database $dbForProject
    ): array {
        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 1000;
        $offset = 0;
        $count = 0;

        while (
            !empty($attrs = Authorization::skip(fn() => $dbForProject->find('attributes', [
            Query::limit($limit),
            Query::offset($offset),
            ])))
        ) {
            $count += count($attrs);

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
                    'type' => Mapper::fromCollectionAttribute(
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
                    Mapper::argumentsFor('mutate')
                );

                $queryFields[$collectionId . 'Get'] = [
                    'type' => $objectType,
                    'args' => Mapper::argumentsFor('id'),
                    'resolve' => Resolvers::documentGet(
                        $utopia,
                        $dbForProject,
                        $databaseId,
                        $collectionId
                    )
                ];
                $queryFields[$collectionId . 'List'] = [
                    'type' => Type::listOf($objectType),
                    'args' => Mapper::argumentsFor('list'),
                    'resolve' => Resolvers::documentList(
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
                    'resolve' => Resolvers::documentCreate(
                        $utopia,
                        $dbForProject,
                        $databaseId,
                        $collectionId,
                    )
                ];
                $mutationFields[$collectionId . 'Update'] = [
                    'type' => $objectType,
                    'args' => \array_merge(
                        Mapper::argumentsFor('id'),
                        \array_map(
                            fn($attr) => $attr['type'] = Type::getNullableType($attr['type']),
                            $attributes
                        )
                    ),
                    'resolve' => Resolvers::documentUpdate(
                        $utopia,
                        $dbForProject,
                        $databaseId,
                        $collectionId,
                    )
                ];
                $mutationFields[$collectionId . 'Delete'] = [
                    'type' => Mapper::fromResponseModel(Response::MODEL_NONE),
                    'args' => Mapper::argumentsFor('id'),
                    'resolve' => Resolvers::documentDelete(
                        $utopia,
                        $dbForProject,
                        $databaseId,
                        $collectionId
                    )
                ];
            }
            $offset += $limit;
        }

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    public static function setDirty(string $projectId): void
    {
        self::$dirty[$projectId] = true;
    }
}
