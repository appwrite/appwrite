<?php

namespace Tests\Compat\Database;

use Tests\Compat\Fault;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Query;

/**
 * The database's values across the wire, the same on both runtimes
 * (crates/tools/compat/src/libs/database/codec.rs is the Rust twin).
 *
 * Tags carry the library's objects:
 *   {"$document": {...attributes}}                 a Document (nested tags decoded first)
 *   {"$document": {...}, "$class": "Some\\Class"}  a Document of a custom type (results only)
 *   {"$query": {"method", "attribute", "values"}}   new Query(method, attribute, values)
 *   {"$operator": {"method", "attribute", "values"}} new Operator(method, attribute, values)
 *   {"$object": {...}}                              a non-empty stdClass
 *   {"$bson": <canonical extended JSON>}            a MongoDB\BSON value (UTCDateTime, ObjectId, Regex)
 * Everything else is what Tests\Compat\Codec already decoded: arrays,
 * scalars, `{}` as an empty stdClass, binary strings and non-finite floats.
 *
 * encode() is the inverse: documents, queries and operators become tags,
 * recursively, so results compare objects and their classes, not only
 * their array copies.
 */
final class Values
{
    public static function decode(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (\count($value) === 1 || (\count($value) === 2 && \array_key_exists('$class', $value))) {
                if (\array_key_exists('$document', $value) && \is_array($value['$document'])) {
                    return new Document(self::decodeArray($value['$document']));
                }
                if (\array_key_exists('$document', $value) && $value['$document'] instanceof \stdClass) {
                    return new Document();
                }
            }
            if (\count($value) === 1) {
                if (\array_key_exists('$query', $value)) {
                    $q = self::fields($value['$query']);

                    return new Query((string) ($q['method'] ?? ''), (string) ($q['attribute'] ?? ''), self::decodeArray($q['values'] ?? []));
                }
                if (\array_key_exists('$operator', $value)) {
                    $o = self::fields($value['$operator']);

                    return new Operator((string) ($o['method'] ?? ''), (string) ($o['attribute'] ?? ''), self::decodeArray($o['values'] ?? []));
                }
                if (\array_key_exists('$object', $value)) {
                    return (object) self::decodeArray(self::fields($value['$object']));
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
                $out['$class'] = $value::class;
            }
            if ($out['$document'] === []) {
                $out['$document'] = new \stdClass();
            }

            return $out;
        }
        if ($value instanceof Query) {
            return ['$query' => [
                'method' => $value->getMethod(),
                'attribute' => $value->getAttribute(),
                'values' => self::encodeArray($value->getValues()),
            ]];
        }
        if ($value instanceof Operator) {
            return ['$operator' => [
                'method' => $value->getMethod(),
                'attribute' => $value->getAttribute(),
                'values' => self::encodeArray($value->getValues()),
            ]];
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
        if (\is_object($value)) {
            throw new Fault('cannot encode ' . get_debug_type($value));
        }

        return $value;
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
