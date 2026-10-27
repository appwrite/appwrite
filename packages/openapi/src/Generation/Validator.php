<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Generation;

use Utopia\OpenAPI\Exception\UnsafeValue;
use Utopia\OpenAPI\Model\ArraySchema;
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\MediaType;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Model\Parameter;
use Utopia\OpenAPI\Model\Schema;
use Utopia\OpenAPI\Model\StringSchema;
use Utopia\OpenAPI\Specification;

/**
 * Refuses a specification whose names and values a code generator could not
 * print unescaped and still have them read as names and values.
 */
final class Validator
{
    /** @throws UnsafeValue */
    public static function validate(Specification $specification): void
    {
        self::assert(Rule::Title, $specification->info->title, '#/info/title');
        self::assert(Rule::Version, $specification->info->version, '#/info/version');
        foreach ($specification->servers as $index => $server) {
            self::assert(Rule::Url, $server->url, "#/servers/{$index}/url");
        }
        foreach (array_values($specification->tags) as $index => $tag) {
            self::assert(Rule::Name, $tag->name, "#/tags/{$index}/name");
        }
        foreach ($specification->securitySchemes as $key => $scheme) {
            $location = self::pointer('#/components/securitySchemes', (string) $key);
            self::assert(Rule::Name, (string) $key, $location);
            if ($scheme->name !== null) {
                self::assert(Rule::Name, $scheme->name, "{$location}/name");
            }
        }
        foreach ($specification->schemas as $name => $schema) {
            $location = self::pointer('#/components/schemas', (string) $name);
            self::assert(Rule::Name, (string) $name, $location);
            self::schema($schema, $location);
        }
        foreach ($specification->paths as $path => $item) {
            $location = self::pointer('#/paths', (string) $path);
            self::assert(Rule::Path, (string) $path, $location);
            foreach ($item->parameters as $index => $parameter) {
                self::parameter($parameter, "{$location}/parameters/{$index}");
            }
            foreach ($item->operations as $operation) {
                $base = "{$location}/{$operation->method->value}";
                self::assert(Rule::Name, $operation->id, "{$base}/operationId");
                foreach ($operation->tags as $index => $tag) {
                    self::assert(Rule::Name, $tag, "{$base}/tags/{$index}");
                }
                foreach ($operation->parameters as $index => $parameter) {
                    self::parameter($parameter, "{$base}/parameters/{$index}");
                }
                self::content($operation->requestBody->content ?? [], "{$base}/requestBody/content");
                foreach ($operation->responses as $status => $response) {
                    self::content($response->content, self::pointer("{$base}/responses", (string) $status) . '/content');
                }
            }
        }
    }

    /** @param array<array-key, MediaType> $content */
    private static function content(array $content, string $location): void
    {
        foreach ($content as $name => $type) {
            $base = self::pointer($location, (string) $name);
            self::assert(Rule::MediaType, (string) $name, $base);
            if ($type->schema !== null) {
                self::schema($type->schema, "{$base}/schema");
            }
        }
    }

    private static function parameter(Parameter $parameter, string $location): void
    {
        self::assert(Rule::Field, $parameter->name, "{$location}/name");
        if ($parameter->schema !== null) {
            self::schema($parameter->schema, "{$location}/schema");
        }
    }

    private static function schema(Schema $schema, string $location): void
    {
        foreach ($schema->enum as $index => $value) {
            if (\is_string($value)) {
                self::assert(Rule::EnumValue, $value, "{$location}/enum/{$index}");
            }
        }
        if ($schema->enum !== []) {
            if ($schema instanceof StringSchema && $schema->enumName !== null) {
                self::assert(Rule::EnumName, $schema->enumName, "{$location}/title");
            }
            if ($schema->title !== null) {
                self::assert(Rule::EnumName, $schema->title, "{$location}/title");
            }
        }
        if ($schema instanceof ObjectSchema) {
            foreach ($schema->properties as $name => $property) {
                $base = self::pointer("{$location}/properties", (string) $name);
                self::assert(Rule::Field, (string) $name, $base);
                self::schema($property, $base);
            }
            if ($schema->additionalProperties instanceof Schema) {
                self::schema($schema->additionalProperties, "{$location}/additionalProperties");
            }
        } elseif ($schema instanceof ArraySchema) {
            self::schema($schema->items, "{$location}/items");
        } elseif ($schema instanceof CompositeSchema) {
            $keyword = $schema->composition->value ?? 'anyOf';
            foreach ($schema->schemas as $index => $branch) {
                self::schema($branch, "{$location}/{$keyword}/{$index}");
            }
        }
    }

    /** @throws UnsafeValue */
    private static function assert(Rule $rule, string $value, string $location): void
    {
        if (! $rule->accepts($value)) {
            throw new UnsafeValue($rule, $value, $location);
        }
    }

    private static function pointer(string $location, string $token): string
    {
        return $location . '/' . str_replace(['~', '/'], ['~0', '~1'], $token);
    }
}
