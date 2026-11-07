<?php

namespace Tests\Compat\Openapi;

require_once __DIR__ . '/../php-std/Typed.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\PhpStd\Typed;
use Tests\Compat\Session;
use Utopia\OpenAPI\Model\AnySchema;
use Utopia\OpenAPI\Model\ArraySchema;
use Utopia\OpenAPI\Model\BooleanSchema;
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\Composition;
use Utopia\OpenAPI\Model\Discriminator;
use Utopia\OpenAPI\Model\Encoding;
use Utopia\OpenAPI\Model\Example;
use Utopia\OpenAPI\Model\ExternalDocumentation;
use Utopia\OpenAPI\Model\Header;
use Utopia\OpenAPI\Model\HttpMethod;
use Utopia\OpenAPI\Model\IntegerSchema;
use Utopia\OpenAPI\Model\MediaType;
use Utopia\OpenAPI\Model\NeverSchema;
use Utopia\OpenAPI\Model\NumberSchema;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Model\Operation;
use Utopia\OpenAPI\Model\Parameter;
use Utopia\OpenAPI\Model\PathItem;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Model\Schema;
use Utopia\OpenAPI\Model\SecurityRequirement;
use Utopia\OpenAPI\Model\SecurityScheme;
use Utopia\OpenAPI\Model\Server;
use Utopia\OpenAPI\Model\StringSchema;
use Utopia\OpenAPI\Parser;
use Utopia\OpenAPI\Parser\OpenAPI2;
use Utopia\OpenAPI\Parser\OpenAPI30;
use Utopia\OpenAPI\Parser\OpenAPI31;
use Utopia\OpenAPI\Parser\Schema\Dialect;
use Utopia\OpenAPI\Parser\Schema\Reader as SchemaReader;
use Utopia\OpenAPI\Parser\Value;
use Utopia\OpenAPI\Reference\LocalResolver;
use Utopia\OpenAPI\Reference\Reference;
use Utopia\OpenAPI\Reference\ResolutionContext;
use Utopia\OpenAPI\Specification;
use Utopia\OpenAPI\Version;

/**
 * Maps tests/compat/openapi/spec.json operations onto utopia-php/openapi. Glue only: no logic.
 *
 * Documents and free-form values cross as Typed (tests/compat/php-std/Typed.php).
 * A parsed specification is dumped field by field (maps as [key, value]
 * pairs, schemas as {kind, ...keywords, ...annotations}); the Rust adapter
 * (crates/tools/compat/src/libs/openapi.rs) writes the same shape.
 */
final class Adapter implements Base, Benchmarks
{
    private const FIXTURES = __DIR__ . '/../../../packages/openapi/tests/Fixtures/';

    public const SCHEMA = '{"type":"object","required":["id","name"],"properties":{"id":{"type":"integer","format":"int64"},"name":{"type":"string","minLength":1},"tag":{"type":["string","null"]},"status":{"type":"string","enum":["available","adopted"]},"parent":{"$ref":"#/components/schemas/Pet"}},"additionalProperties":false}';

    public const REFERENCES = '{"openapi":"3.1.0","info":{"title":"References","version":"1"},"components":{"schemas":{"Binary":{"type":"string","format":"binary"},"Alias":{"$ref":"#/components/schemas/Binary"},"Chained":{"$ref":"#/components/schemas/Alias"}}}}';

    public function benchmarks(): array
    {
        return [
            'parse_2_0' => ['Parse the OpenAPI 2.0 fixture from JSON', function (Session $s) {
                $json = self::fixture('openapi-2.0.json');

                return fn () => Parser::parse($json);
            }],
            'parse_3_0' => ['Parse the OpenAPI 3.0 fixture from JSON', function (Session $s) {
                $json = self::fixture('openapi-3.0.json');

                return fn () => Parser::parse($json);
            }],
            'parse_3_1' => ['Parse the OpenAPI 3.1 fixture from JSON', function (Session $s) {
                $json = self::fixture('openapi-3.1.json');

                return fn () => Parser::parse($json);
            }],
            'parse_document' => ['Read the decoded OpenAPI 3.1 fixture', function (Session $s) {
                $document = json_decode(self::fixture('openapi-3.1.json'), true);

                return fn () => Parser::parse($document);
            }],
            'schema_read' => ['Read an object schema with five properties', function (Session $s) {
                $raw = json_decode(self::SCHEMA, true);
                $reader = new SchemaReader(Dialect::for(Version::V3_1));

                return fn () => $reader->read($raw, '#/components/schemas/Pet');
            }],
            'resolve_schema' => ['Resolve a two-step schema reference chain', function (Session $s) {
                $spec = Parser::parse(self::REFERENCES);
                $schema = $spec->schemas['Chained'];

                return fn () => $spec->resolveSchema($schema);
            }],
        ];
    }

    public static function fixture(string $name): string
    {
        if (! \in_array($name, ['openapi-2.0.json', 'openapi-3.0.json', 'openapi-3.1.json'], true)) {
            throw new Fault("unknown fixture {$name}");
        }

        return file_get_contents(self::FIXTURES . $name);
    }

    public function operations(): array
    {
        return [
            'openapi.parse' => fn (array $a, Session $s) => self::spec(self::parse($a)),
            'openapi.operation' => fn (array $a, Session $s) => self::operation(
                (self::parse($a)->paths[$a['path']] ?? null)?->operation(HttpMethod::from($a['method'])),
            ),
            'openapi.operations_by_tag' => fn (array $a, Session $s) => array_map(
                static fn (Operation $o): array => [$o->path, $o->method->value, $o->id],
                self::parse($a)->operationsByTag($a['tag']),
            ),
            'openapi.resolve_schema' => function (array $a, Session $s) {
                $spec = self::parse($a);
                $schema = self::locate($spec, $a['at']);
                $resolved = $spec->resolveSchema($schema);
                $name = array_search($resolved, $spec->schemas, true);

                return [
                    'name' => $name === false ? null : (string) $name,
                    'same' => $resolved === $schema,
                    'schema' => self::schema($resolved),
                ];
            },
            'version.from' => fn (array $a, Session $s) => Version::fromDocumentVersion($a['version'])->value,
            'dialect.for' => function (array $a, Session $s) {
                $dialect = Dialect::for(Version::from($a['version']));

                return [
                    'booleanSchemas' => $dialect->booleanSchemas,
                    'typeArrays' => $dialect->typeArrays,
                    'constKeyword' => $dialect->constKeyword,
                ];
            },
            'schema.read' => fn (array $a, Session $s) => self::schema(
                self::reader($a)->read(Typed::decode($a['raw'] ?? null), $a['location'] ?? '#/x'),
            ),
            'schema.read_parameter_fields' => fn (array $a, Session $s) => self::schema(
                self::reader($a)->readParameterFields(self::array($a['data']), $a['location'] ?? '#/x'),
            ),
            'composite.new' => function (array $a, Session $s) {
                $reader = new SchemaReader(Dialect::for(Version::V3_1));
                $schemas = array_map(static fn (mixed $raw): Schema => $reader->read(Typed::decode($raw), '#/member'), $a['schemas'] ?? []);
                $not = isset($a['not']) ? $reader->read(Typed::decode($a['not']), '#/not') : null;
                $discriminator = isset($a['discriminator']) ? new Discriminator(
                    $a['discriminator']['propertyName'],
                    (array) Typed::decode($a['discriminator']['mapping'] ?? []),
                    (array) Typed::decode($a['discriminator']['extensions'] ?? []),
                ) : null;
                $schema = new CompositeSchema(
                    isset($a['composition']) ? Composition::from($a['composition']) : null,
                    $schemas,
                    $not,
                    $discriminator,
                    title: $a['title'] ?? null,
                    description: $a['description'] ?? '',
                    nullable: $a['nullable'] ?? false,
                    default: Typed::decode($a['default'] ?? null),
                    enum: array_values((array) Typed::decode($a['enum'] ?? [])),
                    format: $a['format'] ?? null,
                    readOnly: $a['readOnly'] ?? false,
                    writeOnly: $a['writeOnly'] ?? false,
                    deprecated: $a['deprecated'] ?? false,
                    example: Typed::decode($a['example'] ?? null),
                    extensions: (array) Typed::decode($a['extensions'] ?? []),
                    location: $a['location'] ?? '#',
                );

                return self::schema($schema);
            },
            'value.object' => fn (array $a, Session $s) => Typed::dump(Value::object(Typed::decode($a['value'] ?? null), $a['location'])),
            'value.list' => fn (array $a, Session $s) => Typed::dump(Value::list(Typed::decode($a['value'] ?? null), $a['location'])),
            'value.string_list' => fn (array $a, Session $s) => Typed::dump(Value::stringList(Typed::decode($a['value'] ?? null), $a['location'])),
            'value.required_string' => fn (array $a, Session $s) => Value::requiredString(self::array($a['data']), $a['key'], $a['location']),
            'value.optional_string' => fn (array $a, Session $s) => Value::optionalString(self::array($a['data']), $a['key']),
            'value.nullable_int' => fn (array $a, Session $s) => Value::nullableInt(Typed::decode($a['value'] ?? null), $a['location']),
            'value.nullable_number' => fn (array $a, Session $s) => Typed::dump(Value::nullableNumber(Typed::decode($a['value'] ?? null), $a['location'])),
            'value.extensions' => fn (array $a, Session $s) => Typed::dump(Value::extensions(self::array($a['data']))),
            'reference.is_local' => fn (array $a, Session $s) => (new Reference($a['value']))->isLocal(),
            'resolver.resolve' => fn (array $a, Session $s) => Typed::dump((new LocalResolver(self::array($a['document'])))->resolve(
                new Reference($a['reference']),
                new ResolutionContext($a['trail'] ?? [], $a['baseUri'] ?? null),
            )),
            'resolver.resolve_object' => fn (array $a, Session $s) => Typed::dump(
                (new LocalResolver(self::array($a['document'])))->resolveObject($a['reference'], $a['trail'] ?? []),
            ),
            'operation.security_names' => function (array $a, Session $s) {
                $security = array_map(
                    static fn (mixed $schemes): SecurityRequirement => new SecurityRequirement(self::array($schemes)),
                    $a['security'],
                );
                $operation = new Operation(id: 'test', method: HttpMethod::GET, path: '/test', security: $security);

                return [
                    'accepted' => $operation->acceptedSecuritySchemeNames(),
                    'required' => $operation->requiredSecuritySchemeNames(),
                    'security' => array_map(self::requirement(...), $operation->security),
                ];
            },
            'reader.read' => function (array $a, Session $s) {
                $document = self::array($a['document']);
                $version = Version::from($a['version']);
                $schemas = new SchemaReader(Dialect::for(Version::from($a['dialect'] ?? $a['version'])));
                $reader = match ($a['reader']) {
                    '2.0' => new OpenAPI2($document, $version, $a['sourceVersion'], $schemas),
                    '3.0' => new OpenAPI30($document, $version, $a['sourceVersion'], $schemas),
                    '3.1' => new OpenAPI31($document, $version, $a['sourceVersion'], $schemas),
                    default => throw new Fault("unknown reader {$a['reader']}"),
                };

                return self::spec($reader->read());
            },
        ];
    }

    private static function array(mixed $value): array
    {
        $value = Typed::decode($value);
        // `{}` decodes to an object; Rust's adapter treats it as an array too.
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (! \is_array($value)) {
            throw new Fault('expected an array');
        }

        return $value;
    }

    private static function reader(array $a): SchemaReader
    {
        return new SchemaReader(Dialect::for(Version::from($a['version'] ?? '3.1')));
    }

    /** {text} | {fixture} | {document}, optional {version}, {via: "read"} */
    private static function parse(array $a): Specification
    {
        $input = match (true) {
            isset($a['fixture']) => self::fixture($a['fixture']),
            \array_key_exists('text', $a) => $a['text'],
            default => self::array($a['document']),
        };
        $version = isset($a['version']) ? Version::from($a['version']) : null;

        return ($a['via'] ?? 'parse') === 'read' ? (new Parser())->read($input, $version) : Parser::parse($input, $version);
    }

    /** ['schemas', name] | ['property', schema, property] | ['response', path, method, status, media] | ['body', path, method, media] */
    private static function locate(Specification $spec, array $at): Schema
    {
        $schema = match ($at[0]) {
            'schemas' => $spec->schemas[$at[1]] ?? null,
            'property' => ($object = $spec->schemas[$at[1]] ?? null) instanceof ObjectSchema ? $object->properties[$at[2]] ?? null : null,
            'response' => ($spec->paths[$at[1]] ?? null)?->operation(HttpMethod::from($at[2]))?->responses[$at[3]]->content[$at[4]]->schema,
            'body' => ($spec->paths[$at[1]] ?? null)?->operation(HttpMethod::from($at[2]))?->requestBody?->content[$at[3]]->schema,
            default => null,
        };

        return $schema ?? throw new Fault('no schema at ' . json_encode($at));
    }

    /** @param array<int|string, mixed> $map */
    private static function pairs(array $map, callable $dump): array
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [(string) $key, $dump($value)];
        }

        return $pairs;
    }

    private static function spec(Specification $spec): array
    {
        return [
            'version' => $spec->version->value,
            'sourceVersion' => $spec->sourceVersion,
            'jsonSchemaDialect' => $spec->jsonSchemaDialect,
            'info' => [
                'title' => $spec->info->title,
                'description' => $spec->info->description,
                'version' => $spec->info->version,
                'termsOfService' => $spec->info->termsOfService,
                'contact' => $spec->info->contact === null ? null : [
                    'name' => $spec->info->contact->name,
                    'url' => $spec->info->contact->url,
                    'email' => $spec->info->contact->email,
                    'extensions' => Typed::dump($spec->info->contact->extensions),
                ],
                'license' => $spec->info->license === null ? null : [
                    'name' => $spec->info->license->name,
                    'url' => $spec->info->license->url,
                    'identifier' => $spec->info->license->identifier,
                    'extensions' => Typed::dump($spec->info->license->extensions),
                ],
                'extensions' => Typed::dump($spec->info->extensions),
            ],
            'servers' => array_map(self::server(...), $spec->servers),
            'tags' => self::pairs($spec->tags, fn ($tag) => [
                'name' => $tag->name,
                'description' => $tag->description,
                'externalDocumentation' => self::externalDocumentation($tag->externalDocumentation),
                'extensions' => Typed::dump($tag->extensions),
            ]),
            'paths' => self::pairs($spec->paths, self::pathItem(...)),
            'schemas' => self::pairs($spec->schemas, self::schema(...)),
            'securitySchemes' => self::pairs($spec->securitySchemes, self::securityScheme(...)),
            'security' => array_map(self::requirement(...), $spec->security),
            'extensions' => Typed::dump($spec->extensions),
            'externalDocumentation' => self::externalDocumentation($spec->externalDocumentation),
            'operations' => array_map(static fn (Operation $o): array => [$o->path, $o->method->value], $spec->operations()),
        ];
    }

    private static function externalDocumentation(?ExternalDocumentation $docs): ?array
    {
        return $docs === null ? null : [
            'url' => $docs->url,
            'description' => $docs->description,
            'extensions' => Typed::dump($docs->extensions),
        ];
    }

    private static function server(Server $server): array
    {
        return [
            'url' => $server->url,
            'description' => $server->description,
            'variables' => self::pairs($server->variables, fn ($variable) => [
                'default' => $variable->default,
                'enum' => $variable->enum,
                'description' => $variable->description,
                'extensions' => Typed::dump($variable->extensions),
            ]),
            'extensions' => Typed::dump($server->extensions),
        ];
    }

    private static function requirement(SecurityRequirement $requirement): array
    {
        return self::pairs($requirement->schemes, fn (array $scopes) => $scopes);
    }

    private static function securityScheme(SecurityScheme $scheme): array
    {
        return [
            'type' => $scheme->type->value,
            'description' => $scheme->description,
            'name' => $scheme->name,
            'location' => $scheme->location?->value,
            'scheme' => $scheme->scheme,
            'bearerFormat' => $scheme->bearerFormat,
            'flows' => self::pairs($scheme->flows, fn ($flow) => [
                'authorizationUrl' => $flow->authorizationUrl,
                'tokenUrl' => $flow->tokenUrl,
                'refreshUrl' => $flow->refreshUrl,
                'scopes' => self::pairs($flow->scopes, fn (string $description) => $description),
            ]),
            'openIdConnectUrl' => $scheme->openIdConnectUrl,
            'extensions' => Typed::dump($scheme->extensions),
        ];
    }

    private static function pathItem(PathItem $item): array
    {
        return [
            'path' => $item->path,
            'operations' => self::pairs($item->operations, self::operation(...)),
            'parameters' => array_map(self::parameter(...), $item->parameters),
            'summary' => $item->summary,
            'description' => $item->description,
            'servers' => array_map(self::server(...), $item->servers),
            'extensions' => Typed::dump($item->extensions),
        ];
    }

    private static function operation(?Operation $operation): ?array
    {
        if ($operation === null) {
            return null;
        }

        return [
            'id' => $operation->id,
            'method' => $operation->method->value,
            'path' => $operation->path,
            'tags' => $operation->tags,
            'summary' => $operation->summary,
            'description' => $operation->description,
            'deprecated' => $operation->deprecated,
            'parameters' => array_map(self::parameter(...), $operation->parameters),
            'requestBody' => $operation->requestBody === null ? null : [
                'description' => $operation->requestBody->description,
                'required' => $operation->requestBody->required,
                'content' => self::pairs($operation->requestBody->content, self::mediaType(...)),
                'extensions' => Typed::dump($operation->requestBody->extensions),
            ],
            'responses' => self::pairs($operation->responses, fn ($response) => [
                'description' => $response->description,
                'headers' => self::pairs($response->headers, self::header(...)),
                'content' => self::pairs($response->content, self::mediaType(...)),
                'extensions' => Typed::dump($response->extensions),
            ]),
            'security' => array_map(self::requirement(...), $operation->security),
            'servers' => array_map(self::server(...), $operation->servers),
            'externalDocumentation' => self::externalDocumentation($operation->externalDocumentation),
            'extensions' => Typed::dump($operation->extensions),
            'accepted' => $operation->acceptedSecuritySchemeNames(),
            'required' => $operation->requiredSecuritySchemeNames(),
        ];
    }

    private static function parameter(Parameter $parameter): array
    {
        return [
            'name' => $parameter->name,
            'location' => $parameter->location->value,
            'description' => $parameter->description,
            'required' => $parameter->required,
            'deprecated' => $parameter->deprecated,
            'allowEmptyValue' => $parameter->allowEmptyValue,
            'schema' => self::schema($parameter->schema),
            'content' => self::pairs($parameter->content, self::mediaType(...)),
            'style' => $parameter->style,
            'explode' => $parameter->explode,
            'allowReserved' => $parameter->allowReserved,
            'extensions' => Typed::dump($parameter->extensions),
            'identity' => $parameter->identity(),
        ];
    }

    private static function mediaType(MediaType $media): array
    {
        return [
            'schema' => self::schema($media->schema),
            'example' => Typed::dump($media->example),
            'examples' => self::pairs($media->examples, fn (Example $example) => [
                'summary' => $example->summary,
                'description' => $example->description,
                'value' => Typed::dump($example->value),
                'externalValue' => $example->externalValue,
                'extensions' => Typed::dump($example->extensions),
            ]),
            'encoding' => self::pairs($media->encoding, fn (Encoding $encoding) => [
                'contentType' => $encoding->contentType,
                'headers' => self::pairs($encoding->headers, self::header(...)),
                'style' => $encoding->style,
                'explode' => $encoding->explode,
                'allowReserved' => $encoding->allowReserved,
                'extensions' => Typed::dump($encoding->extensions),
            ]),
            'extensions' => Typed::dump($media->extensions),
        ];
    }

    private static function header(Header $header): array
    {
        return [
            'description' => $header->description,
            'required' => $header->required,
            'deprecated' => $header->deprecated,
            'schema' => self::schema($header->schema),
            'content' => self::pairs($header->content, self::mediaType(...)),
            'style' => $header->style,
            'explode' => $header->explode,
            'extensions' => Typed::dump($header->extensions),
        ];
    }

    private static function schema(?Schema $schema): ?array
    {
        if ($schema === null) {
            return null;
        }
        $out = match (true) {
            $schema instanceof ObjectSchema => [
                'kind' => 'object',
                'properties' => self::pairs($schema->properties, self::schema(...)),
                'required' => $schema->required,
                'additionalProperties' => $schema->additionalProperties instanceof Schema
                    ? self::schema($schema->additionalProperties)
                    : $schema->additionalProperties,
                'minProperties' => $schema->minProperties,
                'maxProperties' => $schema->maxProperties,
            ],
            $schema instanceof ArraySchema => [
                'kind' => 'array',
                'items' => self::schema($schema->items),
                'minItems' => $schema->minItems,
                'maxItems' => $schema->maxItems,
                'uniqueItems' => $schema->uniqueItems,
            ],
            $schema instanceof StringSchema => [
                'kind' => 'string',
                'minLength' => $schema->minLength,
                'maxLength' => $schema->maxLength,
                'pattern' => $schema->pattern,
                'enumName' => $schema->enumName,
                'enumKeys' => $schema->enumKeys,
                'open' => $schema->open,
            ],
            $schema instanceof IntegerSchema, $schema instanceof NumberSchema => [
                'kind' => $schema instanceof IntegerSchema ? 'integer' : 'number',
                'minimum' => Typed::dump($schema->minimum),
                'maximum' => Typed::dump($schema->maximum),
                'exclusiveMinimum' => $schema->exclusiveMinimum,
                'exclusiveMaximum' => $schema->exclusiveMaximum,
                'multipleOf' => Typed::dump($schema->multipleOf),
            ],
            $schema instanceof BooleanSchema => ['kind' => 'boolean'],
            $schema instanceof CompositeSchema => [
                'kind' => 'composite',
                'composition' => $schema->composition?->value,
                'schemas' => array_map(self::schema(...), $schema->schemas),
                'not' => self::schema($schema->not),
                'discriminator' => $schema->discriminator === null ? null : [
                    'propertyName' => $schema->discriminator->propertyName,
                    'mapping' => self::pairs($schema->discriminator->mapping, fn (string $reference) => $reference),
                    'extensions' => Typed::dump($schema->discriminator->extensions),
                ],
                'stringEnum' => self::schema($schema->stringEnum()),
                'openStringEnumBranch' => self::schema($schema->openStringEnumBranch()),
                'conditionalReferences' => array_map(static fn (array $case): array => [
                    'reference' => $case['reference'],
                    'conditions' => array_map(static fn (array $condition): array => [
                        'propertyName' => $condition['propertyName'],
                        'value' => Typed::dump($condition['value']),
                    ], $case['conditions']),
                ], $schema->conditionalReferences()),
            ],
            $schema instanceof ReferenceSchema => ['kind' => 'reference', 'reference' => $schema->reference],
            $schema instanceof AnySchema => ['kind' => 'any'],
            $schema instanceof NeverSchema => ['kind' => 'never'],
            default => throw new Fault('unknown schema class ' . $schema::class),
        };

        return $out + [
            'title' => $schema->title,
            'description' => $schema->description,
            'nullable' => $schema->nullable,
            'default' => Typed::dump($schema->default),
            'enum' => Typed::dump($schema->enum),
            'format' => $schema->format,
            'readOnly' => $schema->readOnly,
            'writeOnly' => $schema->writeOnly,
            'deprecated' => $schema->deprecated,
            'example' => Typed::dump($schema->example),
            'extensions' => Typed::dump($schema->extensions),
        ];
    }
}
