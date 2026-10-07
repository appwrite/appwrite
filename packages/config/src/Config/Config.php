<?php

namespace Utopia\Config;

use ReflectionAttribute;
use Utopia\Config\Attribute\ConfigKey;
use Utopia\Config\Attribute\Key;
use Utopia\Config\Exception\Load;

class Config
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $className
     * @return T
     */
    public static function load(Source $source, Parser $parser, string $className): mixed
    {
        $contents = $source->getContents();
        if ($contents === null) {
            throw new Load('Loader returned null contents.');
        }

        $reflection = new \ReflectionClass($className);

        // Reject methods (including a constructor) before instantiating, so a
        // schema with a required constructor argument or a side-effecting
        // constructor raises Load instead of running or throwing on `new`.
        if (\count($reflection->getMethods()) > 0) {
            throw new Load("Class {$className} cannot have any functions.");
        }

        $data = $parser->parse($contents, $reflection);

        $instance = new $className();

        foreach ($reflection->getProperties() as $property) {
            $attributeFound = false;

            foreach ($property->getAttributes(Key::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributeFound = true;
                $key = $attribute->newInstance();

                $value = self::resolveValue($data, $key->name);

                // A present null is kept; only a genuinely absent key is skipped
                // or, when required, reported missing.
                if ($value === self::missing()) {
                    if ($key->required) {
                        throw new Load("Missing required key: {$key->name}");
                    }
                    continue;
                }

                $expectedType = $property->getType();
                if ($expectedType === null) {
                    throw new Load("Property {$property->name} is missing a type.");
                }

                if (! $key->validator->isValid($value)) {
                    throw new Load("Invalid value for {$key->name}: {$key->validator->getDescription()}");
                }

                $propertyName = $property->name;
                try {
                    $instance->$propertyName = $value;
                } catch (\TypeError $e) {
                    throw new Load("Invalid value for {$key->name}: does not match the type of property {$property->name}.", 0, $e);
                }
            }

            foreach ($property->getAttributes(ConfigKey::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributeFound = true;

                $key = $attribute->newInstance();
                $keyName = $key->name;
                if (empty($keyName)) {
                    $keyName = $property->name;
                }

                $value = self::resolveValue($data, $keyName);

                if ($value === self::missing()) {
                    if ($key->required) {
                        throw new Load("Missing required key: {$keyName}");
                    }
                    continue;
                }

                $expectedType = $property->getType();
                if ($expectedType === null || !method_exists($expectedType, 'getName')) {
                    throw new Load("Property {$property->name} is missing a type.");
                }

                $expectedClass = $expectedType->getName();

                if (!($value instanceof $expectedClass)) {
                    throw new Load("Invalid value for {$keyName}: Must be instance of {$expectedClass}.");
                }

                $propertyName = $property->name;
                $instance->$propertyName = $value;
            }

            if (!$attributeFound) {
                throw new Load("Property {$property->name} is missing attribute syntax.");
            }
        }

        return $instance;
    }

    /**
     * Sentinel returned by value resolution when a key is genuinely absent, so
     * an explicit null in the config can be told apart from a missing key.
     */
    protected static function missing(): object
    {
        static $missing;

        return $missing ??= new \stdClass();
    }

    /**
     * @param array<string, mixed> $data The data array to search in
     * @return mixed The value if found, the missing() sentinel otherwise
     */
    protected static function resolveValue(array $data, string $key): mixed
    {
        // Exact match
        if (\array_key_exists($key, $data)) {
            return $data[$key];
        }

        // Dot notation
        $parts = explode('.', $key);
        return self::resolveValueRecursive($data, $parts, 0);
    }

    /**
     * @param array<string, mixed> $data Current data context
     * @param array<int, string> $parts Remaining parts of the key
     * @return mixed The value if found, the missing() sentinel otherwise
     */
    protected static function resolveValueRecursive(array $data, array $parts, int $index): mixed
    {
        if ($index >= \count($parts)) {
            return self::missing();
        }

        if ($index === \count($parts) - 1) {
            return \array_key_exists($parts[$index], $data) ? $data[$parts[$index]] : self::missing();
        }

        for ($length = 1; $length <= \count($parts) - $index; $length++) {
            $keyParts = \array_slice($parts, $index, $length);
            $key = implode('.', $keyParts);

            if (\array_key_exists($key, $data)) {
                $value = $data[$key];

                if ($index + $length === \count($parts)) {
                    return $value;
                }

                if (\is_array($value)) {
                    $result = self::resolveValueRecursive($value, $parts, $index + $length);
                    if ($result !== self::missing()) {
                        return $result;
                    }
                }
            }
        }

        return self::missing();
    }
}
