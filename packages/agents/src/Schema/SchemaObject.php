<?php

namespace Utopia\Agents\Schema;

/**
 * Schema Object, represents a JSON Schema Object
 *
 * @link https://json-schema.org/understanding-json-schema/reference/object#object
 */
class SchemaObject
{
    public const TYPE_STRING = 'string';

    public const TYPE_ARRAY = 'array';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_INTEGER = 'integer';

    public const TYPE_NUMBER = 'number';

    public const TYPE_OBJECT = 'object';

    public const TYPE_NULL = 'null';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $properties;

    /**
     * @param  array<string, array<string, mixed>>  $properties
     */
    public function __construct(array $properties = [])
    {
        foreach ($properties as $name => $property) {
            if (! $this->validateProperty($name, $property)) {
                throw new \InvalidArgumentException(
                    'Invalid type '.var_export($property['type'], true)." for property '$name'. Must be one of: ".implode(', ', self::getValidTypes())
                );
            }
        }
        $this->properties = $properties;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getProperty(string $name): ?array
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Add a property to the object
     *
     * @param  string  $name  - name of the property
     * @param  array<string, mixed>  $property  - property definition (must be defined in JSON Schema format)
     *
     * @link https://json-schema.org/understanding-json-schema/reference/object#properties
     */
    public function addProperty(string $name, array $property): self
    {
        if (! $this->validateProperty($name, $property)) {
            throw new \InvalidArgumentException(
                'Invalid type '.var_export($property['type'], true)." for property '$name'. Must be one of: ".implode(', ', self::getValidTypes())
            );
        }
        $this->properties[$name] = $property;

        return $this;
    }

    public function removeProperty(string $name): self
    {
        unset($this->properties[$name]);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getNames(): array
    {
        return array_keys($this->properties);
    }

    /**
     * Validate a property
     *
     * @param  string  $name  - name of the property
     * @param  array<string, mixed>  $property  - property definition (must be defined in JSON Schema format)
     */
    public function validateProperty(string $name, array $property): bool
    {
        if (! isset($property['type']) || ! in_array($property['type'], self::getValidTypes(), true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_STRING,
            self::TYPE_ARRAY,
            self::TYPE_BOOLEAN,
            self::TYPE_INTEGER,
            self::TYPE_NUMBER,
            self::TYPE_OBJECT,
            self::TYPE_NULL,
        ];
    }
}
