<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser\Schema;

use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Model\AnySchema;
use Utopia\OpenAPI\Model\ArraySchema;
use Utopia\OpenAPI\Model\BooleanSchema;
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\Composition;
use Utopia\OpenAPI\Model\Discriminator;
use Utopia\OpenAPI\Model\IntegerSchema;
use Utopia\OpenAPI\Model\NeverSchema;
use Utopia\OpenAPI\Model\NumberSchema;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Model\Schema;
use Utopia\OpenAPI\Model\StringSchema;
use Utopia\OpenAPI\Parser\Value;

/**
 * Reads a raw schema value into the canonical schema tree.
 *
 * Depends on nothing but its Dialect: schema references are left unexpanded, so
 * reading never needs the document or a reference resolver.
 */
final readonly class Reader
{
    /** Schema keywords a 2.0 parameter or header may carry inline. */
    private const array PARAMETER_FIELDS = [
        'type', 'format', 'items', 'default', 'enum', 'maximum', 'exclusiveMaximum',
        'minimum', 'exclusiveMinimum', 'maxLength', 'minLength', 'pattern',
        'maxItems', 'minItems', 'uniqueItems', 'multipleOf', 'description',
    ];

    public function __construct(private Dialect $dialect) {}

    public function read(mixed $raw, string $location): Schema
    {
        if (\is_bool($raw)) {
            if (! $this->dialect->booleanSchemas) {
                throw new InvalidSpecification("Boolean schemas are only supported by OpenAPI 3.1 at {$location}");
            }

            return $raw ? new AnySchema() : new NeverSchema();
        }

        $data = Value::object($raw, $location);
        if ($this->dialect->constKeyword && \array_key_exists('const', $data) && isset($data['enum'])) {
            $constant = $data['const'];
            $enum = Value::list($data['enum'], "{$location}/enum");
            if (! \is_scalar($constant) && $constant !== null) {
                $constraint = ['const' => $constant];
                unset($data['const']);

                return new CompositeSchema(Composition::ALL_OF, [
                    $this->read($data, $location),
                    $this->read($constraint, $location),
                ], null, $this->discriminator($data), ...$this->common($data), location: $location);
            }
            $matches = array_filter($enum, static fn(mixed $value): bool => $value === $constant
                || ((\is_int($value) || \is_float($value)) && (\is_int($constant) || \is_float($constant)) && $value == $constant));
            if ($matches === []) {
                return new NeverSchema(...$this->common($data));
            }
            $data['enum'] = [$constant];
        }
        $common = $this->common($data);

        if (isset($data['$ref'])) {
            return new ReferenceSchema(...[
                'reference' => Value::requiredString($data, '$ref', $location),
                ...$common,
            ]);
        }

        $nullable = $common['nullable'];
        $type = $data['type'] ?? null;
        if (\is_array($type)) {
            if (! $this->dialect->typeArrays || ! array_is_list($type)) {
                throw new InvalidSpecification("Invalid schema type at {$location}/type");
            }
            $types = array_values(array_filter($type, static fn(mixed $item): bool => $item !== 'null'));
            $nullable = \count($types) !== \count($type);
            $common['nullable'] = $nullable;
            if (\count($types) > 1) {
                $schemas = [];
                foreach ($types as $index => $memberType) {
                    if (! \is_string($memberType)) {
                        throw new InvalidSpecification("Invalid schema type at {$location}/type/{$index}");
                    }
                    $schemas[] = $this->read(['type' => $memberType], "{$location}/type/{$index}");
                }

                return new CompositeSchema(Composition::ANY_OF, $schemas, null, $this->discriminator($data), ...$common, location: $location);
            }
            $type = $types[0] ?? null;
        }
        if ($type !== null && ! \is_string($type)) {
            throw new InvalidSpecification("Invalid schema type at {$location}/type");
        }

        foreach ([Composition::ONE_OF, Composition::ANY_OF, Composition::ALL_OF] as $composition) {
            if (\array_key_exists($composition->value, $data)) {
                $schemas = [];
                foreach (Value::list($data[$composition->value], "{$location}/{$composition->value}") as $index => $schema) {
                    $schemas[] = $this->read($schema, "{$location}/{$composition->value}/{$index}");
                }
                $not = \array_key_exists('not', $data) ? $this->read($data['not'], "{$location}/not") : null;

                return new CompositeSchema($composition, $schemas, $not, $this->discriminator($data), ...$common, location: $location);
            }
        }
        if (\array_key_exists('not', $data)) {
            return new CompositeSchema(null, [], $this->read($data['not'], "{$location}/not"), $this->discriminator($data), ...$common, location: $location);
        }

        // A scalar singleton already fixes the instance type. Preserve that
        // type's constraints instead of dropping them into an AnySchema.
        if ($type === null && \count($common['enum']) === 1) {
            $type = match (true) {
                \is_string($common['enum'][0]) => 'string',
                \is_int($common['enum'][0]), \is_float($common['enum'][0]) => 'number',
                \is_bool($common['enum'][0]) => 'boolean',
                default => null,
            };
        }

        if ($type === null) {
            if (isset($data['properties']) || \array_key_exists('additionalProperties', $data)) {
                $type = 'object';
            } elseif (isset($data['items'])) {
                $type = 'array';
            }
        }

        return match ($type) {
            'string', 'file' => new StringSchema(
                minLength: Value::nullableInt($data['minLength'] ?? null, "{$location}/minLength"),
                maxLength: Value::nullableInt($data['maxLength'] ?? null, "{$location}/maxLength"),
                pattern: Value::optionalString($data, 'pattern'),
                format: $type === 'file' ? 'binary' : $common['format'],
                title: $common['title'],
                description: $common['description'],
                nullable: $nullable,
                default: $common['default'],
                enum: $common['enum'],
                readOnly: $common['readOnly'],
                writeOnly: $common['writeOnly'],
                deprecated: $common['deprecated'],
                example: $common['example'],
                extensions: $common['extensions'],
            ),
            'integer' => $this->integer($data, $location, $common),
            'number' => $this->number($data, $location, $common),
            'boolean' => new BooleanSchema(...$common),
            'array' => new ArraySchema(
                ...[
                    'items' => \array_key_exists('items', $data) ? $this->read($data['items'], "{$location}/items") : new AnySchema(),
                    'minItems' => Value::nullableInt($data['minItems'] ?? null, "{$location}/minItems"),
                    'maxItems' => Value::nullableInt($data['maxItems'] ?? null, "{$location}/maxItems"),
                    'uniqueItems' => (bool) ($data['uniqueItems'] ?? false),
                    ...$common,
                ],
            ),
            'object' => $this->object($data, $location, $common),
            'null' => new AnySchema(...['nullable' => true, ...array_diff_key($common, ['nullable' => true])]),
            null => new AnySchema(...$common),
            default => throw new InvalidSpecification("Unsupported schema type '{$type}' at {$location}"),
        };
    }

    /**
     * Read a schema from the keywords a 2.0 parameter or header carries inline,
     * where there is no nested 'schema' object to read from.
     *
     * @param  array<string, mixed>  $data
     */
    public function readParameterFields(array $data, string $location): Schema
    {
        return $this->read(array_intersect_key($data, array_flip(self::PARAMETER_FIELDS)), $location);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function common(array $data): array
    {
        $enum = isset($data['enum']) ? Value::list($data['enum'], 'schema/enum') : [];
        if ($this->dialect->constKeyword && \array_key_exists('const', $data) && ! isset($data['enum'])) {
            $enum = [$data['const']];
        }

        return [
            'title' => Value::optionalString($data, 'title'),
            'description' => Value::optionalString($data, 'description') ?? '',
            'nullable' => (bool) ($data['nullable'] ?? false),
            'default' => $data['default'] ?? null,
            'enum' => $enum,
            'format' => Value::optionalString($data, 'format'),
            'readOnly' => (bool) ($data['readOnly'] ?? false),
            'writeOnly' => (bool) ($data['writeOnly'] ?? false),
            'deprecated' => (bool) ($data['deprecated'] ?? false),
            'example' => $data['example'] ?? null,
            'extensions' => Value::extensions($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $common
     */
    private function integer(array $data, string $location, array $common): IntegerSchema
    {
        [$minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum] = $this->bounds($data, $location);

        return new IntegerSchema($minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum, Value::nullableNumber($data['multipleOf'] ?? null, "{$location}/multipleOf"), ...$common);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $common
     */
    private function number(array $data, string $location, array $common): NumberSchema
    {
        [$minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum] = $this->bounds($data, $location);

        return new NumberSchema($minimum, $maximum, $exclusiveMinimum, $exclusiveMaximum, Value::nullableNumber($data['multipleOf'] ?? null, "{$location}/multipleOf"), ...$common);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{int|float|null, int|float|null, bool, bool}
     */
    private function bounds(array $data, string $location): array
    {
        $minimum = Value::nullableNumber($data['minimum'] ?? null, "{$location}/minimum");
        $maximum = Value::nullableNumber($data['maximum'] ?? null, "{$location}/maximum");
        $exclusiveMinimum = $data['exclusiveMinimum'] ?? false;
        $exclusiveMaximum = $data['exclusiveMaximum'] ?? false;
        if (\is_int($exclusiveMinimum) || \is_float($exclusiveMinimum)) {
            $minimum = $exclusiveMinimum;
            $exclusiveMinimum = true;
        }
        if (\is_int($exclusiveMaximum) || \is_float($exclusiveMaximum)) {
            $maximum = $exclusiveMaximum;
            $exclusiveMaximum = true;
        }

        return [$minimum, $maximum, (bool) $exclusiveMinimum, (bool) $exclusiveMaximum];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $common
     */
    private function object(array $data, string $location, array $common): ObjectSchema
    {
        $properties = [];
        foreach (Value::object($data['properties'] ?? [], "{$location}/properties") as $name => $schema) {
            $properties[(string) $name] = $this->read($schema, "{$location}/properties/{$name}");
        }
        $required = isset($data['required'])
            ? array_map(strval(...), Value::list($data['required'], "{$location}/required"))
            : [];
        $additional = $data['additionalProperties'] ?? null;
        if ($additional !== null && ! \is_bool($additional)) {
            $additional = $this->read($additional, "{$location}/additionalProperties");
        }

        return new ObjectSchema(...[
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => $additional,
            'minProperties' => Value::nullableInt($data['minProperties'] ?? null, "{$location}/minProperties"),
            'maxProperties' => Value::nullableInt($data['maxProperties'] ?? null, "{$location}/maxProperties"),
            ...$common,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function discriminator(array $data): ?Discriminator
    {
        if (! isset($data['discriminator'])) {
            return null;
        }
        if (\is_string($data['discriminator'])) {
            return new Discriminator($data['discriminator']);
        }
        $value = Value::object($data['discriminator'], 'schema/discriminator');
        $mapping = [];
        foreach (Value::object($value['mapping'] ?? [], 'schema/discriminator/mapping') as $name => $reference) {
            if (! \is_string($reference)) {
                throw new InvalidSpecification('Discriminator mappings must be strings');
            }
            $mapping[(string) $name] = $reference;
        }

        return new Discriminator(
            Value::requiredString($value, 'propertyName', 'schema/discriminator'),
            $mapping,
            Value::extensions($value),
        );
    }
}
