<?php

namespace Appwrite\GraphQL;

use Appwrite\Utopia\Response;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http\Response as SwooleResponse;
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

        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => \array_merge_recursive(
                    $apiSchema['query'],
                    $collectionSchema['query']
                )
            ]),
            'mutation' => new ObjectType([
                'name' => 'Mutation',
                'fields' => \array_merge_recursive(
                    $apiSchema['mutation'],
                    $collectionSchema['mutation']
                )
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
        $queryFields = [];
        $mutationFields = [];
        $response = new Response(new SwooleResponse());
        $models = $response->getModels();

        TypeRegistry::init($models);

        foreach (App::getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                if (\str_starts_with($route->getPath(), '/v1/mock/')) {
                    continue;
                }
                $namespace = $route->getLabel('sdk.namespace', '');
                $methodName = $namespace . \ucfirst($route->getLabel('sdk.method', ''));
                $responseModelNames = $route->getLabel('sdk.response.model', 'none');
                $responseModels = \is_array($responseModelNames)
                    ? \array_map(static fn($m) => $models[$m], $responseModelNames)
                    : [$models[$responseModelNames]];

                foreach ($responseModels as $responseModel) {
                    $type = TypeRegistry::get($responseModel->getType());
                    $description = $route->getDesc();
                    $params = [];

                    foreach ($route->getParams() as $key => $value) {
                        $argType = TypeMapper::typeFromParameter(
                            $utopia,
                            $value['validator'],
                            !$value['optional'],
                            $value['injections']
                        );
                        $params[$key] = [
                            'type' => $argType,
                            'description' => $value['description'],
                            'defaultValue' => $value['default']
                        ];
                    }

                    $field = [
                        'type' => $type,
                        'description' => $description,
                        'args' => $params,
                        'resolve' => Resolvers::resolveAPIRequest($utopia, $route)
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

        $schema = [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];

        return $schema;
    }

    /**
     * Iterates all a projects attributes and builds GraphQL
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
            !empty($attrs = Authorization::skip(fn() => $dbForProject->find(
                collection: 'attributes',
                queries: [
                Query::limit($limit),
                Query::offset($offset),
                ]
            )))
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
                    $escapedKey = str_replace('$', '_', $key);
                    $collections[$collectionId][$escapedKey] = [
                        'type' => TypeMapper::typeFromAttribute($type, $array, $required),
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
                        TypeRegistry::defaultArgsFor('mutate')
                    );
                    $queryFields[$collectionId . 'Get'] = [
                        'type' => $objectType,
                        'args' => TypeRegistry::defaultArgsFor('id'),
                        'resolve' => Resolvers::resolveDocumentGet(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId
                        )
                    ];
                    $queryFields[$collectionId . 'List'] = [
                        'type' => $objectType,
                        'args' => TypeRegistry::defaultArgsFor('list'),
                        'resolve' => Resolvers::resolveDocumentList(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId
                        ),
                        'complexity' => fn(int $complexity, array $args) => $complexity * $args['limit'],
                    ];
                    $mutationFields[$collectionId . 'Create'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => Resolvers::resolveDocumentMutate(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId,
                            'POST'
                        )
                    ];
                    $mutationFields[$collectionId . 'Update'] = [
                        'type' => $objectType,
                        'args' => $attributes,
                        'resolve' => Resolvers::resolveDocumentMutate(
                            $utopia,
                            $dbForProject,
                            $databaseId,
                            $collectionId,
                            'PATCH'
                        )
                    ];
                    $mutationFields[$collectionId . 'Delete'] = [
                        'type' => $objectType,
                        'args' => TypeRegistry::defaultArgsFor('id'),
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
