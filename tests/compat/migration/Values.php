<?php

declare(strict_types=1);

namespace Tests\Compat\Migration;

use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Warning;

/**
 * Values across the wire for the migration adapter: handles inside
 * arguments, resources described by their getters, cache entries, errors.
 */
final class Values
{
    /**
     * Arguments with every `{"$handle": ...}` replaced by its object.
     *
     * @param array<mixed> $args
     * @return array<mixed>
     */
    public static function args(array $args, Session $s): array
    {
        foreach ($args as $key => $value) {
            if (\is_array($value)) {
                $args[$key] = (\count($value) === 1 && isset($value['$handle'])) ? $s->get($value) : self::args($value, $s);
            }
        }

        return $args;
    }

    public static function resource(mixed $handle, Session $s): Resource
    {
        $resource = $s->get($handle);
        if (!$resource instanceof Resource) {
            throw new Fault('expected a resource handle');
        }

        return $resource;
    }

    /**
     * @param array<mixed> $handles
     * @return array<Resource>
     */
    public static function resources(array $handles, Session $s): array
    {
        return \array_map(static fn (mixed $h) => self::resource($h, $s), $handles);
    }

    /**
     * Every public getter without required parameters (`get*`, `is*`), by
     * name: what a caller can read of the resource.
     *
     * @return array<string, mixed>
     */
    public static function describe(Resource $resource): array
    {
        $out = ['class' => $resource::class];
        $methods = (new \ReflectionObject($resource))->getMethods(\ReflectionMethod::IS_PUBLIC);
        \usort($methods, static fn ($a, $b) => \strcmp($a->getName(), $b->getName()));
        foreach ($methods as $method) {
            $name = $method->getName();
            if ($method->getNumberOfRequiredParameters() > 0 || !(\str_starts_with($name, 'get') || \str_starts_with($name, 'is'))) {
                continue;
            }
            try {
                $out[$name] = $method->invoke($method->isStatic() ? null : $resource);
            } catch (\Throwable $e) {
                $out[$name] = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
            }
        }

        return $out;
    }

    /**
     * Cache entries as a list of `{"key", "entry"}` (keys carry uniqid()
     * sequences, which are scrubbed in values only): resources as
     * `{"$resource": name, "id", "status", "message"}`, row counts as their
     * strings.
     *
     * @param array<mixed> $entries
     * @return list<array{key: int|string, entry: mixed}>
     */
    public static function entries(array $entries): array
    {
        $out = [];
        foreach ($entries as $key => $e) {
            $out[] = ['key' => $key, 'entry' => $e instanceof Resource ? self::summary($e) : $e];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Resource $resource): array
    {
        return [
            '$resource' => $resource::getName(),
            'id' => $resource->getId(),
            'status' => $resource->getStatus(),
            'message' => $resource->getMessage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function exception(MigrationException $e): array
    {
        $previous = $e->getPrevious();

        return [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'resourceName' => $e->getResourceName(),
            'resourceGroup' => $e->getResourceGroup(),
            'resourceId' => $e->getResourceId(),
            'json' => $e->jsonSerialize(),
            'previous' => $previous === null ? null : ['class' => $previous::class, 'message' => $previous->getMessage()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function warning(Warning $w): array
    {
        return [
            'resourceName' => $w->getResourceName(),
            'resourceGroup' => $w->getResourceGroup(),
            'resourceId' => $w->getResourceId(),
            'message' => $w->getMessage(),
        ];
    }

    /**
     * A transfer callback recording each batch it is called with (name, id
     * and status of each resource), or throwing the exception
     * `{"throw": {"class", "message"}}` names.
     *
     * @param array<mixed>|null $spec
     * @param array<mixed> $calls
     */
    public static function callback(?array $spec, array &$calls): \Closure
    {
        return static function (array $resources) use ($spec, &$calls): void {
            $calls[] = \array_map(self::summary(...), $resources);
            if (isset($spec['throw'])) {
                $class = $spec['throw']['class'];

                throw new $class($spec['throw']['message']);
            }
        };
    }

    public static function prop(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);

        return $property->getValue($object);
    }

    /**
     * @param array<string> $names
     * @return array<string, mixed>
     */
    public static function props(object $object, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = \property_exists($object, $name) ? self::prop($object, $name) : null;
        }

        return $out;
    }
}
