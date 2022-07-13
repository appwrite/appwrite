<?php

namespace Appwrite\GraphQL;

use Appwrite\Utopia\Response;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
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
        Database $dbForProject
    ): Schema {
        App::setResource('current', fn() => $utopia);

        $start = microtime(true);
        $register = $utopia->getResource('register');
        $envVersion = App::getEnv('_APP_VERSION');
        $schemaVersion = $register->has('apiSchemaVersion') ? $register->get('apiSchemaVersion') : '';
        $collectionSchemaDirty = $register->has('schemaDirty') && $register->get('schemaDirty');
        $apiSchemaDirty = \version_compare($envVersion, $schemaVersion, "!=");

        if (
            !$collectionSchemaDirty
            && !$apiSchemaDirty
            && $register->has('fullSchema')
        ) {
            self::printBuildTimeFrom($start);

            return $register->get('fullSchema');
        }

        $apiSchema = self::getApiSchema($utopia, $register, $apiSchemaDirty, $envVersion);
        $collectionSchema = self::getCollectionSchema($utopia, $register, $dbForProject, $collectionSchemaDirty);
        $schema = self::collateSchema($apiSchema, $collectionSchema);

        self::printBuildTimeFrom($start);

        $register->set('fullSchema', fn() => $schema);

        return $schema;
    }

    /**
     * This function iterates all API routes and builds a GraphQL
     * schema defining types and resolvers for all response models
     *
     * @param App $utopia
     * @return array
     * @throws \Exception
     */
    public static function buildAPISchema(App $utopia): array
    {
        $start = microtime(true);
        $queryFields = [];
        $mutationFields = [];
        $response = new Response(new SwooleResponse());
        $models = $response->getModels();

        TypeRegistry::init($models);

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
                    $type = TypeRegistry::get($responseModel->getType());
                    $description = $route->getDesc();
                    $args = [];

                    foreach ($route->getParams() as $key => $value) {
                        $argType = TypeMapper::typeFromParameter(
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

        $timeElapsedMillis = (microtime(true) - $start) * 1000;
        $timeElapsedMillis = \number_format((float)$timeElapsedMillis, 3, '.', '');
        Console::info("[INFO] Built GraphQL REST API Schema in ${timeElapsedMillis}ms");

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    /**
     * Iterates all a projects attributes and builds GraphQL queries and mutations for the collections they make up.
     *
     * @param App $utopia
     * @param Database $dbForProject
     * @return array
     * @throws \Exception
     */
    public static function buildCollectionSchema(
        App $utopia,
        Database $dbForProject
    ): array {
        $start = microtime(true);

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
                        )
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

        $timeElapsedMillis = (microtime(true) - $start) * 1000;
        $timeElapsedMillis = \number_format((float)$timeElapsedMillis, 3, '.', '');
        Console::info('[INFO] Built GraphQL Project Collection Schema in ' . $timeElapsedMillis . 'ms (' . $count . ' attributes)');

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }

    private static function getApiSchema(
        App $utopia,
        Registry $register,
        bool $apiSchemaDirty,
        string $envVersion
    ): array {
        if ($register->has('apiSchema') && !$apiSchemaDirty) {
            $apiSchema = $register->get('apiSchema');
        } else {
            $apiSchema = self::buildAPISchema($utopia);
            $register->set('apiSchema', fn() => $apiSchema);
            $register->set('apiSchemaVersion', fn() => $envVersion);
        }
        return $apiSchema;
    }

    private static function getCollectionSchema(
        App $utopia,
        Registry $register,
        Database $dbForProject,
        bool $collectionSchemaDirty
    ): array {
        if ($register->has('collectionSchema') && !$collectionSchemaDirty) {
            $collectionSchema = $register->get('collectionSchema');
        } else {
            $collectionSchema = self::buildCollectionSchema($utopia, $dbForProject);
            $register->set('collectionSchema', fn() => $collectionSchema);
            $register->set('schemaDirty', fn() => false);
        }
        return $collectionSchema;
    }

    private static function collateSchema(
        array $apiSchema,
        array $collectionSchema
    ): Schema {
        $queryFields = \array_merge_recursive($apiSchema['query'], $collectionSchema['query']);
        $mutationFields = \array_merge_recursive($apiSchema['mutation'], $collectionSchema['mutation']);

        \ksort($queryFields);
        \ksort($mutationFields);

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
     * @param $start
     * @return void
     */
    private static function printBuildTimeFrom($start): void
    {
        $timeElapsedMillis = (\microtime(true) - $start) * 1000;
        $timeElapsedMillis = \number_format((float)$timeElapsedMillis, 3, '.', '');
        Console::info('[INFO] Built GraphQL Schema in ' . $timeElapsedMillis . 'ms');
    }
}
