<?php

declare(strict_types=1);

namespace Utopia\OpenAPI;

use Utopia\OpenAPI\Model\ExternalDocumentation;
use Utopia\OpenAPI\Model\Info;
use Utopia\OpenAPI\Model\Operation;
use Utopia\OpenAPI\Model\PathItem;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Model\Schema;
use Utopia\OpenAPI\Model\SecurityRequirement;
use Utopia\OpenAPI\Model\SecurityScheme;
use Utopia\OpenAPI\Model\Server;
use Utopia\OpenAPI\Model\Tag;

final readonly class Specification
{
    /**
     * @param  list<Server>  $servers
     * @param  array<string, Tag>  $tags
     * @param  array<string, PathItem>  $paths
     * @param  array<string, Schema>  $schemas
     * @param  array<string, SecurityScheme>  $securitySchemes
     * @param  list<SecurityRequirement>  $security
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public Version $version,
        public Info $info,
        public array $servers = [],
        public array $tags = [],
        public array $paths = [],
        public array $schemas = [],
        public array $securitySchemes = [],
        public array $security = [],
        public array $extensions = [],
        public string $sourceVersion = '',
        public ?string $jsonSchemaDialect = null,
        public ?ExternalDocumentation $externalDocumentation = null,
    ) {}

    /**
     * Resolve chained local component references without expanding recursive schema graphs.
     */
    public function resolveSchema(Schema $schema): Schema
    {
        $visited = [];
        while ($schema instanceof ReferenceSchema) {
            if (! str_starts_with($schema->reference, '#')) {
                break;
            }

            $name = null;
            $reference = rawurldecode($schema->reference);
            foreach (['#/components/schemas/', '#/definitions/'] as $prefix) {
                if (str_starts_with($reference, $prefix)) {
                    $name = str_replace(['~1', '~0'], ['/', '~'], substr($reference, \strlen($prefix)));
                    break;
                }
            }

            if ($name === null || isset($visited[$name]) || ! isset($this->schemas[$name])) {
                break;
            }
            $visited[$name] = true;
            $schema = $this->schemas[$name];
        }

        return $schema;
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->paths as $path) {
            array_push($operations, ...array_values($path->operations));
        }

        return $operations;
    }

    /** @return list<Operation> */
    public function operationsByTag(string $tag): array
    {
        return array_values(array_filter(
            $this->operations(),
            static fn(Operation $operation): bool => \in_array($tag, $operation->tags, true),
        ));
    }
}
