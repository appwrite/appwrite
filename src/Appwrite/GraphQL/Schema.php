<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\GraphQL\Types\Registry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema as GQLSchema;
use stdClass;
use Utopia\App;
use Utopia\Console;
use Utopia\Route;

class Schema
{
    private Registry $registry;
    private ?Mapper $mapper = null;
    private string $projectId;

    /**
     * Reserved GraphQL type names that cannot be used for collection types.
     */
    private const array RESERVED_TYPES = [
        'Query', 'Mutation', 'Subscription',
        'String', 'Int', 'Float', 'Boolean', 'ID',
        'Input', 'Enum', '__Type', '__Field', '__InputValue',
        '__EnumValue', '__Directive', '__Schema'
    ];

    /**
     * Create a new Schema instance.
     *
     * @param string $projectId The project ID for this schema
     */
    public function __construct(string $projectId)
    {
        $this->projectId = $projectId;
        $this->registry = new Registry($projectId);
    }

    /**
     * Get the project ID.
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Get the registry instance.
     */
    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    /**
     * Get the mapper instance
     */
    public function getMapper(): ?Mapper
    {
        return $this->mapper;
    }

    /**
     * Build a GraphQL schema for a specific project.
     * Uses LRU cache for collection-based schemas.
     *
     * @param App $utopia
     * @param Cache $cache The schema cache instance
     * @param callable $complexity Function to calculate complexity
     * @param callable $attributes Function to get attributes
     * @param array $urls Array of functions to get urls for specific method types
     * @param array $params Array of functions to build parameters for specific method types
     * @return GQLSchema
     * @throws \Exception
     */
    public function build(
        App $utopia,
        Cache $cache,
        callable $complexity,
        callable $attributes,
        array $urls,
        array $params,
    ): GQLSchema {
        App::setResource('utopia:graphql', static function () use ($utopia) {
            return $utopia;
        });

        $cached = $cache->get($this->projectId);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // Build API schema fresh for each Schema instance to ensure types are properly registered
            // in this instance's Registry. The full schema is cached by projectId, so this only
            // runs on cache miss.
            $api = $this->api($utopia, $complexity);

            $collections = $this->collections(
                $utopia,
                $complexity,
                $attributes,
                $urls,
                $params,
            );

            $queries = \array_merge(
                $api['query'],
                $collections['query']
            );

            $mutations = \array_merge(
                $api['mutation'],
                $collections['mutation']
            );

            \ksort($queries);
            \ksort($mutations);

            $schema = new GQLSchema([
                'query' => new ObjectType([
                    'name' => 'Query',
                    'fields' => $queries
                ]),
                'mutation' => new ObjectType([
                    'name' => 'Mutation',
                    'fields' => $mutations
                ])
            ]);

            $cache->set($this->projectId, $schema);

            return $schema;
        } catch (\Throwable $e) {
            // Clear registry on failure to prevent inconsistent state
            $this->registry->clear();
            throw $e;
        }
    }

    /**
     * This function iterates all API routes and builds a GraphQL
     * schema defining types and resolvers for all response models.
     *
     * @param App $utopia
     * @param callable $complexity
     * @return array
     * @throws \Exception
     */
    protected function api(App $utopia, callable $complexity): array
    {
        $models = $utopia->getResource('response')->getModels();
        $this->mapper = new Mapper($this->registry, $models);

        $queries = [];
        $mutations = [];

        foreach ($utopia->getRoutes() as $routes) {
            foreach ($routes as $route) {
                /** @var Route $route */

                /** @var \Appwrite\SDK\Method $sdk  */
                $sdk = $route->getLabel('sdk', false);

                if (empty($sdk)) {
                    continue;
                }

                if (!\is_array($sdk)) {
                    $sdk = [$sdk];
                }

                foreach ($sdk as $method) {
                    $namespace = $method->getNamespace();
                    $methodName = $method->getMethodName();
                    $name = $namespace . \ucfirst($methodName);

                    foreach ($this->mapper->route($utopia, $route, $method, $complexity) as $field) {
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
                                Console::warning("Unsupported method for GraphQL schema generation: {$route->getMethod()}");
                        }
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
     * Iterates all of a project's attributes and builds GraphQL
     * queries and mutations for the collections they make up.
     *
     * @param App $utopia
     * @param callable(int $complexity, array $args): int $complexity
     * @param callable(int $limit, string $last): array $attributes
     * @param array<string, array<string, callable(string $databaseId, string $collectionId, array $args): string>> $urls
     * @param array<string, array<string, callable(string $databaseId, string $collectionId, array $args): string>> $params
     * @return array{query: array, mutation: array} Array containing query and mutation field definitions
     * @throws \Exception
     */
    protected function collections(
        App $utopia,
        callable $complexity,
        callable $attributes,
        array $urls,
        array $params,
    ): array {
        $collections = [];
        $queryFields = [];
        $mutationFields = [];
        $limit = 1000;
        $last = null;

        while (!empty($attrs = $attributes($limit, $last))) {
            foreach ($attrs as $attr) {
                $databaseId = $attr->getAttribute('databaseId');
                $collectionId = $attr->getAttribute('collectionId');
                $databaseType = $attr->getAttribute('databaseType', 'legacy');
                $key = $attr->getAttribute('key');
                $type = $attr->getAttribute('type');
                $array = $attr->getAttribute('array');
                $required = $attr->getAttribute('required');
                $default = $attr->getAttribute('default');
                $escapedKey = \str_replace('$', '', $key);

                // Use composite key for collection grouping
                $collectionKey = "{$databaseId}_{$collectionId}";

                $collections[$collectionKey]['databaseId'] = $databaseId;
                $collections[$collectionKey]['collectionId'] = $collectionId;
                $collections[$collectionKey]['databaseType'] = $databaseType;
                $collections[$collectionKey]['attributes'][$escapedKey] = [
                    'type' => $this->mapper->attribute(
                        $type,
                        $array,
                        $required
                    ),
                    'defaultValue' => $default,
                ];
            }

            // Use the last Document as cursor for pagination
            $last = \end($attrs) ?: null;
        }

        foreach ($collections as $collectionData) {
            $databaseId = $collectionData['databaseId'];
            $collectionId = $collectionData['collectionId'];
            $databaseType = $collectionData['databaseType'];
            $attributes = $collectionData['attributes'];

            // Get URLs and params for this database type
            $typeUrls = $urls[$databaseType] ?? $urls['legacy'];
            $typeParams = $params[$databaseType] ?? $params['legacy'];

            // Create unique type name for this project's collection
            $typeName = $this->projectId . \ucfirst($collectionId);

            if (\in_array($typeName, self::RESERVED_TYPES)) {
                throw new \Exception("Type name collision with reserved type: {$typeName}");
            }

            if ($this->registry->has($typeName)) {
                throw new \Exception("Type name collision detected: {$typeName} already exists in registry");
            }

            $objectType = new ObjectType([
                'name' => $typeName,
                'fields' => \array_merge(
                    ["_id" => ['type' => Type::string()]],
                    $attributes
                ),
            ]);

            $mutateAttributes = \array_merge(
                $attributes,
                $this->mapper->args('mutate')
            );

            // Prefix field names with collection ID to avoid conflicts
            $queryFields[$collectionId . 'Get'] = [
                'type' => $objectType,
                'args' => $this->mapper->args('id'),
                'resolve' => Resolvers::documentGet(
                    $utopia,
                    $databaseId,
                    $collectionId,
                    $typeUrls['read'],
                )
            ];

            // Determine the list key based on database type (rows for tablesdb, documents for legacy)
            $listKey = $databaseType === 'tablesdb'
                ? 'rows'
                : 'documents';

            $queryFields[$collectionId . 'List'] = [
                'type' => Type::listOf($objectType),
                'args' => $this->mapper->args('list'),
                'resolve' => Resolvers::documentList(
                    $utopia,
                    $databaseId,
                    $collectionId,
                    $typeUrls['list'],
                    $typeParams['list'],
                    $listKey,
                ),
                'complexity' => $complexity,
            ];

            $mutationFields[$collectionId . 'Create'] = [
                'type' => $objectType,
                'args' => $mutateAttributes,
                'resolve' => Resolvers::documentCreate(
                    $utopia,
                    $databaseId,
                    $collectionId,
                    $typeUrls['create'],
                    $typeParams['create'],
                )
            ];
            $mutationFields[$collectionId . 'Update'] = [
                'type' => $objectType,
                'args' => \array_merge(
                    $this->mapper->args('id'),
                    \array_map(
                        fn ($attr) => ['type' => Type::getNullableType($attr['type'])],
                        $mutateAttributes
                    )
                ),
                'resolve' => Resolvers::documentUpdate(
                    $utopia,
                    $databaseId,
                    $collectionId,
                    $typeUrls['update'],
                    $typeParams['update'],
                )
            ];
            $mutationFields[$collectionId . 'Delete'] = [
                'type' => $this->mapper->model('none'),
                'args' => $this->mapper->args('id'),
                'resolve' => Resolvers::documentDelete(
                    $utopia,
                    $databaseId,
                    $collectionId,
                    $typeUrls['delete'],
                )
            ];
        }

        return [
            'query' => $queryFields,
            'mutation' => $mutationFields
        ];
    }
}
