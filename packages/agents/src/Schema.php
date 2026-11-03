<?php

namespace Utopia\Agents;

/**
 * A JSON Schema, used for tool inputs and structured output.
 *
 * Objects built with object() list every property as required and forbid
 * extra properties, which is what strict structured output modes expect.
 * Wrap a property in nullable() to make it optional in practice.
 *
 * validate() covers the keywords models are asked to follow: type, enum,
 * const, anyOf/oneOf, properties, required, additionalProperties, items,
 * minItems/maxItems, minLength/maxLength and the numeric bounds.
 */
final readonly class Schema
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        public array $definition,
    ) {
    }

    /**
     * @param  array<string, Schema>  $properties
     * @param  list<string>|null  $required  Defaults to every property
     */
    public static function object(array $properties, ?array $required = null, string $description = ''): self
    {
        $definition = [
            'type' => 'object',
            'properties' => array_map(fn (Schema $property) => $property->toArray(), $properties),
            'required' => $required ?? array_keys($properties),
            'additionalProperties' => false,
        ];

        return self::described($definition, $description);
    }

    public static function string(string $description = ''): self
    {
        return self::described(['type' => 'string'], $description);
    }

    public static function number(string $description = ''): self
    {
        return self::described(['type' => 'number'], $description);
    }

    public static function integer(string $description = ''): self
    {
        return self::described(['type' => 'integer'], $description);
    }

    public static function boolean(string $description = ''): self
    {
        return self::described(['type' => 'boolean'], $description);
    }

    public static function array(Schema $items, string $description = ''): self
    {
        return self::described(['type' => 'array', 'items' => $items->toArray()], $description);
    }

    /**
     * @param  list<string|int|float|bool>  $values
     */
    public static function enum(array $values, string $description = ''): self
    {
        $strings = array_filter($values, fn ($value) => \is_string($value));
        $definition = ['enum' => $values];
        if (\count($strings) === \count($values)) {
            $definition = ['type' => 'string', 'enum' => $values];
        }

        return self::described($definition, $description);
    }

    /**
     * Accept null as well as the given schema.
     */
    public static function nullable(Schema $schema): self
    {
        $definition = $schema->definition;
        $type = $definition['type'] ?? null;

        if (\is_string($type)) {
            $definition['type'] = [$type, 'null'];
            if (isset($definition['enum']) && \is_array($definition['enum'])) {
                $definition['enum'][] = null;
            }

            return new self($definition);
        }

        return new self(['anyOf' => [$definition, ['type' => 'null']]]);
    }

    /**
     * Wrap an existing JSON Schema document.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self($definition);
    }

    public function describe(string $description): self
    {
        return self::described($this->definition, $description);
    }

    public function description(): string
    {
        $description = $this->definition['description'] ?? '';

        return \is_string($description) ? $description : '';
    }

    /**
     * The schema as an array ready for JSON encoding. Empty property maps
     * become objects so they encode as {} rather than [].
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = $this->definition;
        if (isset($definition['properties']) && $definition['properties'] === []) {
            $definition['properties'] = new \stdClass();
        }

        return $definition;
    }

    /**
     * Check a decoded JSON value against the schema.
     *
     * @return list<string> One message per violation, prefixed with its path; empty when valid
     */
    public function validate(mixed $value): array
    {
        $errors = [];
        $this->check($this->definition, $value, '$', $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function described(array $definition, string $description): self
    {
        if ($description !== '') {
            $definition['description'] = $description;
        }

        return new self($definition);
    }

    /**
     * @param  array<mixed>  $definition
     * @param  list<string>  $errors
     */
    private function check(array $definition, mixed $value, string $path, array &$errors): void
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $definition[$keyword] ?? null;
            if (! \is_array($branches)) {
                continue;
            }

            $matched = false;
            foreach ($branches as $branch) {
                $branchErrors = [];
                if (\is_array($branch)) {
                    $this->check($branch, $value, $path, $branchErrors);
                }
                if ($branchErrors === []) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $errors[] = "{$path}: does not match any allowed schema";

                return;
            }
        }

        if (\array_key_exists('const', $definition) && $value !== $definition['const']) {
            $errors[] = "{$path}: must equal ".$this->encode($definition['const']);

            return;
        }

        if (isset($definition['enum']) && \is_array($definition['enum']) && ! \in_array($value, $definition['enum'], true)) {
            $errors[] = "{$path}: must be one of ".$this->encode($definition['enum']);

            return;
        }

        $types = $definition['type'] ?? null;
        if (\is_string($types)) {
            $types = [$types];
        }
        if (\is_array($types)) {
            $matches = array_filter($types, fn ($type) => \is_string($type) && $this->is($type, $value));
            if ($matches === []) {
                $errors[] = "{$path}: must be of type ".implode(' or ', array_filter($types, 'is_string'));

                return;
            }
        }

        if (\is_array($value) && ($value === [] || ! array_is_list($value)) && ! $this->expects($types, 'array')) {
            $this->checkObject($definition, $value, $path, $errors);
        } elseif (\is_array($value)) {
            $this->checkArray($definition, $value, $path, $errors);
        } elseif (\is_string($value)) {
            $length = mb_strlen($value);
            if (isset($definition['minLength']) && \is_int($definition['minLength']) && $length < $definition['minLength']) {
                $errors[] = "{$path}: must be at least {$definition['minLength']} characters";
            }
            if (isset($definition['maxLength']) && \is_int($definition['maxLength']) && $length > $definition['maxLength']) {
                $errors[] = "{$path}: must be at most {$definition['maxLength']} characters";
            }
        } elseif (\is_int($value) || \is_float($value)) {
            $this->checkNumber($definition, $value, $path, $errors);
        }
    }

    /**
     * @param  array<mixed>  $definition
     * @param  array<mixed>  $value
     * @param  list<string>  $errors
     */
    private function checkObject(array $definition, array $value, string $path, array &$errors): void
    {
        $required = $definition['required'] ?? [];
        if (\is_array($required)) {
            foreach ($required as $name) {
                if (\is_string($name) && ! \array_key_exists($name, $value)) {
                    $errors[] = "{$path}.{$name}: is required";
                }
            }
        }

        $properties = $definition['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }
        if (! \is_array($properties)) {
            $properties = [];
        }

        $additional = $definition['additionalProperties'] ?? true;
        foreach ($value as $name => $item) {
            $name = (string) $name;
            $property = $properties[$name] ?? null;
            if (\is_array($property)) {
                $this->check($property, $item, "{$path}.{$name}", $errors);
            } elseif ($additional === false) {
                $errors[] = "{$path}.{$name}: is not an allowed property";
            } elseif (\is_array($additional)) {
                $this->check($additional, $item, "{$path}.{$name}", $errors);
            }
        }
    }

    /**
     * @param  array<mixed>  $definition
     * @param  array<mixed>  $value
     * @param  list<string>  $errors
     */
    private function checkArray(array $definition, array $value, string $path, array &$errors): void
    {
        $count = \count($value);
        if (isset($definition['minItems']) && \is_int($definition['minItems']) && $count < $definition['minItems']) {
            $errors[] = "{$path}: must have at least {$definition['minItems']} items";
        }
        if (isset($definition['maxItems']) && \is_int($definition['maxItems']) && $count > $definition['maxItems']) {
            $errors[] = "{$path}: must have at most {$definition['maxItems']} items";
        }

        $items = $definition['items'] ?? null;
        if (! \is_array($items)) {
            return;
        }

        foreach ($value as $index => $item) {
            $this->check($items, $item, "{$path}[{$index}]", $errors);
        }
    }

    /**
     * @param  array<mixed>  $definition
     * @param  list<string>  $errors
     */
    private function checkNumber(array $definition, int|float $value, string $path, array &$errors): void
    {
        $bounds = [
            'minimum' => fn (int|float $limit) => $value >= $limit,
            'maximum' => fn (int|float $limit) => $value <= $limit,
            'exclusiveMinimum' => fn (int|float $limit) => $value > $limit,
            'exclusiveMaximum' => fn (int|float $limit) => $value < $limit,
        ];

        foreach ($bounds as $keyword => $holds) {
            $limit = $definition[$keyword] ?? null;
            if ((\is_int($limit) || \is_float($limit)) && ! $holds($limit)) {
                $errors[] = "{$path}: violates {$keyword} {$limit}";
            }
        }
    }

    private function is(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => \is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => \is_array($value) && array_is_list($value),
            'string' => \is_string($value),
            'integer' => \is_int($value) || (\is_float($value) && floor($value) === $value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private function expects(mixed $types, string $type): bool
    {
        return \is_array($types) && \in_array($type, $types, true) && ! \in_array('object', $types, true);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
