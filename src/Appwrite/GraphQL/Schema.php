<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema as GQLSchema;
use Utopia\Http\Http;
use Utopia\Http\Exception;
use Utopia\Http\Route;

class Schema
{
    protected static ?GQLSchema $schema = null;
    protected static array $dirty = [];

    /**
     *
     * @param Http $http
     * @param callable $complexity  Function to calculate complexity
     * @param callable $attributes  Function to get attributes
     * @param array $urls           Array of functions to get urls for specific method types
     * @param array $params         Array of functions to build parameters for specific method types
     * @return GQLSchema
     * @throws Exception
     */
    public static function build(
        Http $http,
        callable $complexity,
        callable $attributes,
        array $urls,
        array $params,
    ): GQLSchema {
        Http::setResource('utopia:graphql', static function () use ($http) {
            return $http;
        });

        if (!empty(self::$schema)) {
            return self::$schema;
        }

        $api = static::api(
            $http,
            $complexity
        );
        //$collections = static::collections(
        //    $http,
        //    $complexity,
        //    $attributes,
        //    $urls,
        //    $params,
        //);

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
     * @param Http $http
     * @param callable $complexity
     * @return array
     * @throws Exception
     */
    protected static function api(Http $http, callable $complexity): array
    {
        Mapper::init($http
            ->getResource('response')
            ->getModels());

        $queries = [];
        $mutations = [];

        foreach ($http->getRoutes() as $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                $namespace = $route->getLabel('sdk.namespace', '');
                $method = $route->getLabel('sdk.method', '');
                $name = $namespace . \ucfirst($method);

                if (empty($name)) {
                    continue;
                }

                foreach (Mapper::route($http, $route, $complexity) as $field) {
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
     * @param Http $http
     * @param callable $complexity
     * @param callable $attributes
     * @param array $urls
     * @param array $params
     * @return array
     * @throws \Exception
     */
    protected static function collections(
        Http $http,
        callable $complexity,
        callable $attributes,
        array $urls,
        array $params,
    ): array {
        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 1000;
        $offset = 0;

        while (!empty($attrs = $attributes($limit, $offset))) {
            foreach ($attrs as $attr) {
                if ($attr['status'] !== 'available') {
                    continue;
                }
                $databaseId = $attr['databaseId'];
                $collectionId = $attr['collectionId'];
                $key = $attr['key'];
                $type = $attr['type'];
                $array = $attr['array'];
                $required = $attr['required'];
                $default = $attr['default'];
                $escapedKey = str_replace('$', '', $key);
                $collections[$collectionId][$escapedKey] = [
                    'type' => Mapper::attribute(
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
                    Mapper::args('mutate')
                );

                $queryFields[$collectionId . 'Get'] = [
                    'type' => $objectType,
                    'args' => Mapper::args('id'),
                    'resolve' => Resolvers::documentGet(
                        $http,
                        $databaseId,
                        $collectionId,
                        $urls['get'],
                    )
                ];
                $queryFields[$collectionId . 'List'] = [
                    'type' => Type::listOf($objectType),
                    'args' => Mapper::args('list'),
                    'resolve' => Resolvers::documentList(
                        $http,
                        $databaseId,
                        $collectionId,
                        $urls['list'],
                        $params['list'],
                    ),
                    'complexity' => $complexity,
                ];

                $mutationFields[$collectionId . 'Create'] = [
                    'type' => $objectType,
                    'args' => $attributes,
                    'resolve' => Resolvers::documentCreate(
                        $http,
                        $databaseId,
                        $collectionId,
                        $urls['create'],
                        $params['create'],
                    )
                ];
                $mutationFields[$collectionId . 'Update'] = [
                    'type' => $objectType,
                    'args' => \array_merge(
                        Mapper::args('id'),
                        \array_map(
                            fn($attr) => $attr['type'] = Type::getNullableType($attr['type']),
                            $attributes
                        )
                    ),
                    'resolve' => Resolvers::documentUpdate(
                        $http,
                        $databaseId,
                        $collectionId,
                        $urls['update'],
                        $params['update'],
                    )
                ];
                $mutationFields[$collectionId . 'Delete'] = [
                    'type' => Mapper::model('none'),
                    'args' => Mapper::args('id'),
                    'resolve' => Resolvers::documentDelete(
                        $http,
                        $databaseId,
                        $collectionId,
                        $urls['delete'],
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
