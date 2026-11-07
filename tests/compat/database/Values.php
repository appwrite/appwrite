<?php

namespace Tests\Compat\Database;

use Tests\Compat\Fault;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\OperatorType;
use Utopia\Database\Query;

/**
 * The database's values across the wire, the same on both runtimes
 * (crates/tools/compat/src/libs/database/codec.rs is the Rust twin).
 *
 * Tags carry the library's objects:
 *   {"$document": {...attributes}}                 a Document (nested tags decoded first)
 *   {"$document": {...}, "$class": "<name>"}       a Document subclass: `Collection`, or a custom
 *                                                  document type (results only)
 *   {"$query": {"method", "attribute", "values", "alias"?}}  new Query(method, attribute, values, alias)
 *   {"$operator": {"method", "attribute", "values"}} new Operator(method, attribute, values)
 *   {"$enum": "<name>::<Case>"}                     an enum case
 *   {"$value": "<name>", "fields": {...}}           a value object (Attribute, Index, Relationship,
 *                                                  Format, Attribute\Update, Limits, ...), its
 *                                                  public properties in declaration order;
 *                                                  decoded by setting them without the
 *                                                  constructor, or, for a class with state of its
 *                                                  own besides them (Adapter\Profile), by its
 *                                                  constructor with them as named arguments
 *   {"$object": {...}}                              a non-empty stdClass
 *   {"$bson": <canonical extended JSON>}            a MongoDB\BSON value (UTCDateTime, ObjectId, Regex)
 *   {"$datetime": "<DateTime string>"}              a \DateTime (`Y-m-d\TH:i:s.uP` when encoded)
 * encode() also writes, never read back: {"$error": {"class", "message"}} for
 * an exception (a Mirror\Failure's), {"$closure": true} for a closure (a
 * Profile's attribute check).
 * A class `<name>` is relative to Utopia\Database (`Attribute`, `Event\Document\Created`), or
 * to Utopia\Query with a `Query\` prefix (`Query\Method`, `Query\Schema\ColumnType`).
 * Everything else is what Tests\Compat\Codec already decoded: arrays,
 * scalars, `{}` as an empty stdClass, binary strings and non-finite floats.
 *
 * encode() is the inverse: documents, queries, operators, enums and value
 * objects become tags, recursively, so results compare objects and their
 * classes, not only their array copies.
 */
final class Values
{
    /** The class a tag names (see the class docs). */
    public static function className(string $name): string
    {
        return str_starts_with($name, 'Query\\')
            ? 'Utopia\\' . $name
            : 'Utopia\\Database\\' . $name;
    }

    /** The tag name of a class (see the class docs). */
    public static function shortName(string $class): ?string
    {
        return match (true) {
            str_starts_with($class, 'Utopia\\Database\\') => substr($class, \strlen('Utopia\\Database\\')),
            str_starts_with($class, 'Utopia\\Query\\') => substr($class, \strlen('Utopia\\')),
            default => null,
        };
    }

    public static function decode(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (\count($value) === 1 || (\count($value) === 2 && \array_key_exists('$class', $value))) {
                if (\array_key_exists('$document', $value)) {
                    $attributes = $value['$document'] instanceof \stdClass ? [] : self::decodeArray($value['$document']);
                    $class = isset($value['$class']) ? self::className((string) $value['$class']) : Document::class;
                    if ($class === \Utopia\Database\Collection::class) {
                        return \Utopia\Database\Collection::fromArray($attributes);
                    }

                    return new Document($attributes);
                }
            }
            if (\count($value) === 2 && \array_key_exists('$value', $value) && \array_key_exists('fields', $value)) {
                return self::object((string) $value['$value'], self::fields($value['fields']));
            }
            if (\count($value) === 1) {
                if (\array_key_exists('$query', $value)) {
                    $q = self::fields($value['$query']);

                    return new Query((string) ($q['method'] ?? ''), (string) ($q['attribute'] ?? ''), self::decodeArray($q['values'] ?? []), (string) ($q['alias'] ?? ''));
                }
                if (\array_key_exists('$operator', $value)) {
                    $o = self::fields($value['$operator']);

                    return new Operator(OperatorType::from((string) ($o['method'] ?? '')), (string) ($o['attribute'] ?? ''), self::decodeArray($o['values'] ?? []));
                }
                if (\array_key_exists('$enum', $value) && \is_string($value['$enum'])) {
                    return self::enum($value['$enum']);
                }
                if (\array_key_exists('$object', $value)) {
                    return (object) self::decodeArray(self::fields($value['$object']));
                }
                if (\array_key_exists('$datetime', $value) && \is_string($value['$datetime'])) {
                    return new \DateTime($value['$datetime']);
                }
                if (\array_key_exists('$bson', $value)) {
                    /** @var \stdClass $decoded */
                    $decoded = \MongoDB\BSON\Document::fromJSON(json_encode(['v' => $value['$bson']], JSON_THROW_ON_ERROR))->toPHP();

                    return $decoded->v;
                }
            }

            return self::decodeArray($value);
        }

        return $value;
    }

    /**
     * An enum case from `<name>::<Case>`.
     */
    public static function enum(string $tag): \UnitEnum
    {
        $at = strrpos($tag, '::');
        if ($at === false) {
            throw new Fault("an enum is <name>::<Case>, not `{$tag}`");
        }
        $class = self::className(substr($tag, 0, $at));
        $case = substr($tag, $at + 2);
        if (!enum_exists($class) || !\defined($class . '::' . $case)) {
            throw new Fault("no enum case `{$tag}`");
        }
        $value = \constant($class . '::' . $case);
        if (!$value instanceof \UnitEnum) {
            throw new Fault("`{$tag}` is not an enum case");
        }

        return $value;
    }

    /**
     * A value object from its class and properties, built without its
     * constructor (its factories validate; the object is what was sent).
     *
     * @param array<string, mixed> $fields
     */
    public static function object(string $name, array $fields): object
    {
        $class = self::className($name);
        if (!class_exists($class)) {
            throw new Fault("no class `{$name}`");
        }
        $reflection = new \ReflectionClass($class);
        // A class with state of its own besides its public properties (an
        // Adapter\Profile) is built by its constructor from them.
        $private = false;
        foreach ($reflection->getProperties() as $p) {
            $private = $private || (!$p->isStatic() && !$p->isPublic());
        }
        $constructor = $reflection->getConstructor();
        if ($private && $constructor !== null && $constructor->isPublic()) {
            return $reflection->newInstanceArgs(array_map(self::decode(...), $fields));
        }
        $object = $reflection->newInstanceWithoutConstructor();
        foreach ($fields as $field => $value) {
            if (!$reflection->hasProperty((string) $field)) {
                throw new Fault("{$name} has no property `{$field}`");
            }
            $reflection->getProperty((string) $field)->setValue($object, self::decode($value));
        }

        return $object;
    }

    /**
     * @param mixed $value
     * @return array<mixed>
     */
    public static function decodeArray(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            return [];
        }
        if (!\is_array($value)) {
            throw new Fault('expected an array, got ' . get_debug_type($value));
        }

        return array_map(self::decode(...), $value);
    }

    /**
     * A document from an argument: a `$document` tag or a plain array.
     */
    public static function document(mixed $value): Document
    {
        $value = self::decode($value);
        if ($value instanceof Document) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return new Document();
        }
        if (!\is_array($value)) {
            throw new Fault('expected a document, got ' . get_debug_type($value));
        }

        return new Document($value);
    }

    /**
     * Documents from a list argument.
     *
     * @return array<Document>
     */
    public static function documents(mixed $value): array
    {
        return array_map(self::document(...), self::list($value));
    }

    /**
     * Queries from a list argument: `$query` tags, or arrays parsed with
     * Query::parseQuery (strings with Query::parse).
     *
     * @return array<Query>
     */
    public static function queries(mixed $value): array
    {
        return array_map(static function (mixed $q): Query {
            if (\is_string($q)) {
                return Query::parse($q);
            }
            $q = self::decode($q);

            return $q instanceof Query ? $q : Query::parseQuery($q);
        }, self::list($value));
    }

    /**
     * @return array<mixed>
     */
    public static function list(mixed $value): array
    {
        if ($value === null || $value instanceof \stdClass) {
            return [];
        }
        if (!\is_array($value)) {
            throw new Fault('expected a list, got ' . get_debug_type($value));
        }

        return array_values($value);
    }

    public static function encode(mixed $value): mixed
    {
        if ($value instanceof Document) {
            $out = ['$document' => self::encodeArray(iterator_to_array($value))];
            if ($value::class !== Document::class) {
                $out['$class'] = self::shortName($value::class) ?? $value::class;
            }
            if ($out['$document'] === []) {
                $out['$document'] = new \stdClass();
            }

            return $out;
        }
        if ($value instanceof \Utopia\Query\Query) {
            $out = [
                'method' => $value->getMethod()->value,
                'attribute' => $value->getAttribute(),
                'values' => self::encodeArray($value->getValues()),
            ];
            if ($value->getAlias() !== '') {
                $out['alias'] = $value->getAlias();
            }

            return ['$query' => $out];
        }
        if ($value instanceof Operator) {
            return ['$operator' => [
                'method' => $value->getMethod()->value,
                'attribute' => $value->getAttribute(),
                'values' => self::encodeArray($value->getValues()),
            ]];
        }
        if ($value instanceof \UnitEnum) {
            return ['$enum' => (self::shortName($value::class) ?? $value::class) . '::' . $value->name];
        }
        if ($value instanceof \stdClass) {
            $properties = (array) $value;

            return $properties === [] ? $value : ['$object' => self::encodeArray($properties)];
        }
        if (\is_array($value)) {
            return self::encodeArray($value);
        }
        if ($value instanceof \MongoDB\BSON\Type) {
            return ['$bson' => json_decode(\MongoDB\BSON\Document::fromPHP(['v' => $value])->toCanonicalExtendedJSON(), true, 512, JSON_THROW_ON_ERROR)['v']];
        }
        if ($value instanceof \DateTimeInterface) {
            return ['$datetime' => $value->format('Y-m-d\TH:i:s.uP')];
        }
        if ($value instanceof \Throwable) {
            return ['$error' => ['class' => $value::class, 'message' => $value->getMessage()]];
        }
        if ($value instanceof \Closure) {
            return ['$closure' => true];
        }
        if (\is_object($value) && self::shortName($value::class) !== null) {
            return self::encodeObject($value);
        }
        if (\is_object($value)) {
            throw new Fault('cannot encode ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * A value object of the library: its class and its initialized public
     * instance properties, in declaration order (parents' first).
     *
     * @return array{'$value': string, fields: array<string, mixed>|\stdClass}
     */
    public static function encodeObject(object $value): array
    {
        $fields = [];
        $chain = [];
        for ($class = new \ReflectionClass($value); $class !== false; $class = $class->getParentClass()) {
            array_unshift($chain, $class);
        }
        foreach ($chain as $class) {
            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                if ($p->isStatic() || $p->getDeclaringClass()->getName() !== $class->getName() || !$p->isInitialized($value)) {
                    continue;
                }
                $fields[$p->getName()] = self::encode($p->getValue($value));
            }
        }

        return ['$value' => self::shortName($value::class) ?? $value::class, 'fields' => $fields === [] ? new \stdClass() : $fields];
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function encodeArray(array $value): array
    {
        return array_map(self::encode(...), $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            return [];
        }
        if (!\is_array($value)) {
            throw new Fault('expected an object, got ' . get_debug_type($value));
        }

        return $value;
    }
}
