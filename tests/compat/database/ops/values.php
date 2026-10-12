<?php

// query.*, operator.*: Utopia\Database\Query and Utopia\Database\Operator.
// Twin: crates/tools/compat/src/libs/database/values.rs. Queries and
// operators cross as `$query` / `$operator` tags (Values.php); results that
// hold them are encoded back to tags.

use Tests\Compat\Database\Values;
use Utopia\Database\Operator;
use Utopia\Database\Query;

$query = static fn (array $a, string $key = 'query'): Query => Values::queries([$a[$key] ?? null])[0];
$operator = static function (array $a, string $key = 'operator'): Operator {
    $o = Values::decode($a[$key] ?? null);

    return $o instanceof Operator ? $o : Operator::parseOperator(Values::decodeArray($o));
};
$q = static fn (Query $query): mixed => Values::encode($query);
$o = static fn (Operator $operator): mixed => Values::encode($operator);
$values = static fn (array $a, string $key = 'values'): array => Values::decodeArray($a[$key] ?? []);
$value = static fn (array $a, string $key = 'value'): mixed => Values::decode($a[$key] ?? null);

return [
    // Query: construction, accessors and setters.
    'query.new' => fn (array $a) => $q(new Query($a['method'], $a['attribute'] ?? '', $values($a))),
    'query.inspect' => function (array $a) use ($query) {
        $x = $query($a);

        return Values::encode([
            'method' => $x->getMethod(),
            'attribute' => $x->getAttribute(),
            'values' => $x->getValues(),
            'value' => $x->getValue(),
            'valueOr' => $x->getValue('default'),
            'isNested' => $x->isNested(),
            'isSpatialQuery' => $x->isSpatialQuery(),
            'onArray' => $x->onArray(),
            'attributeType' => $x->getAttributeType(),
            'isSpatialAttribute' => $x->isSpatialAttribute(),
            'isObjectAttribute' => $x->isObjectAttribute(),
        ]);
    },
    'query.set' => function (array $a) use ($query, $q, $values, $value) {
        $x = $query($a);
        if (\array_key_exists('method', $a)) {
            $x->setMethod($a['method']);
        }
        if (\array_key_exists('attribute', $a)) {
            $x->setAttribute($a['attribute']);
        }
        if (\array_key_exists('values', $a)) {
            $x->setValues($values($a));
        }
        if (\array_key_exists('value', $a)) {
            $x->setValue($value($a));
        }

        return $q($x);
    },
    'query.attribute_type' => function (array $a) use ($query) {
        $x = $query($a);
        $x->setAttributeType($a['type']);
        $x->setOnArray($a['onArray'] ?? false);

        return [
            'attributeType' => $x->getAttributeType(),
            'isSpatialAttribute' => $x->isSpatialAttribute(),
            'isObjectAttribute' => $x->isObjectAttribute(),
            'onArray' => $x->onArray(),
        ];
    },
    'query.is_method' => fn (array $a) => Query::isMethod($a['value']),
    'query.constants' => fn (array $a) => [
        'types' => Query::TYPES,
        'vectorTypes' => Query::VECTOR_TYPES,
        'defaultAlias' => Query::DEFAULT_ALIAS,
    ],

    // Query: parsing and serialization.
    'query.parse' => fn (array $a) => $q(Query::parse($a['query'])),
    'query.parse_query' => fn (array $a) => $q(Query::parseQuery($values($a, 'query'))),
    'query.parse_queries' => fn (array $a) => Values::encode(Query::parseQueries($a['queries'])),
    'query.to_array' => fn (array $a) => Values::encode($query($a)->toArray()),
    'query.to_string' => fn (array $a) => $query($a)->toString(),
    'query.shape' => fn (array $a) => $query($a)->shape(),
    'query.fingerprint' => fn (array $a) => Query::fingerprint($values($a, 'queries')),

    // Query: grouping.
    'query.get_by_type' => fn (array $a) => Values::encode(Query::getByType(Values::queries($a['queries'] ?? []), $a['types'] ?? [])),
    'query.get_cursor_queries' => fn (array $a) => Values::encode(Query::getCursorQueries(Values::queries($a['queries'] ?? []))),
    'query.group_by_type' => fn (array $a) => Values::encode(Query::groupByType($values($a, 'queries'))),

    // Query: static constructors.
    'query.equal' => fn (array $a) => $q(Query::equal($a['attribute'], $values($a))),
    'query.not_equal' => fn (array $a) => $q(Query::notEqual($a['attribute'], $value($a))),
    'query.less_than' => fn (array $a) => $q(Query::lessThan($a['attribute'], $a['value'])),
    'query.less_than_equal' => fn (array $a) => $q(Query::lessThanEqual($a['attribute'], $a['value'])),
    'query.greater_than' => fn (array $a) => $q(Query::greaterThan($a['attribute'], $a['value'])),
    'query.greater_than_equal' => fn (array $a) => $q(Query::greaterThanEqual($a['attribute'], $a['value'])),
    'query.contains' => fn (array $a) => $q(Query::contains($a['attribute'], $values($a))),
    'query.contains_any' => fn (array $a) => $q(Query::containsAny($a['attribute'], $values($a))),
    'query.not_contains' => fn (array $a) => $q(Query::notContains($a['attribute'], $values($a))),
    'query.contains_all' => fn (array $a) => $q(Query::containsAll($a['attribute'], $values($a))),
    'query.between' => fn (array $a) => $q(Query::between($a['attribute'], $a['start'], $a['end'])),
    'query.not_between' => fn (array $a) => $q(Query::notBetween($a['attribute'], $a['start'], $a['end'])),
    'query.search' => fn (array $a) => $q(Query::search($a['attribute'], $a['value'])),
    'query.not_search' => fn (array $a) => $q(Query::notSearch($a['attribute'], $a['value'])),
    'query.starts_with' => fn (array $a) => $q(Query::startsWith($a['attribute'], $a['value'])),
    'query.not_starts_with' => fn (array $a) => $q(Query::notStartsWith($a['attribute'], $a['value'])),
    'query.ends_with' => fn (array $a) => $q(Query::endsWith($a['attribute'], $a['value'])),
    'query.not_ends_with' => fn (array $a) => $q(Query::notEndsWith($a['attribute'], $a['value'])),
    'query.regex' => fn (array $a) => $q(Query::regex($a['attribute'], $a['pattern'])),
    'query.select' => fn (array $a) => $q(Query::select($values($a, 'attributes'))),
    'query.order_desc' => fn (array $a) => $q(Query::orderDesc($a['attribute'] ?? '')),
    'query.order_asc' => fn (array $a) => $q(Query::orderAsc($a['attribute'] ?? '')),
    'query.order_random' => fn (array $a) => $q(Query::orderRandom()),
    'query.limit' => fn (array $a) => $q(Query::limit($a['value'])),
    'query.offset' => fn (array $a) => $q(Query::offset($a['value'])),
    'query.cursor_after' => fn (array $a) => $q(Query::cursorAfter(Values::document($a['document'] ?? []))),
    'query.cursor_before' => fn (array $a) => $q(Query::cursorBefore(Values::document($a['document'] ?? []))),
    'query.is_null' => fn (array $a) => $q(Query::isNull($a['attribute'])),
    'query.is_not_null' => fn (array $a) => $q(Query::isNotNull($a['attribute'])),
    'query.created_before' => fn (array $a) => $q(Query::createdBefore($a['value'])),
    'query.created_after' => fn (array $a) => $q(Query::createdAfter($a['value'])),
    'query.updated_before' => fn (array $a) => $q(Query::updatedBefore($a['value'])),
    'query.updated_after' => fn (array $a) => $q(Query::updatedAfter($a['value'])),
    'query.created_between' => fn (array $a) => $q(Query::createdBetween($a['start'], $a['end'])),
    'query.updated_between' => fn (array $a) => $q(Query::updatedBetween($a['start'], $a['end'])),
    'query.or' => fn (array $a) => $q(Query::or(Values::queries($a['queries'] ?? []))),
    'query.and' => fn (array $a) => $q(Query::and(Values::queries($a['queries'] ?? []))),
    'query.elem_match' => fn (array $a) => $q(Query::elemMatch($a['attribute'], Values::queries($a['queries'] ?? []))),
    'query.distance_equal' => fn (array $a) => $q(Query::distanceEqual($a['attribute'], $values($a), $a['distance'], $a['meters'] ?? false)),
    'query.distance_not_equal' => fn (array $a) => $q(Query::distanceNotEqual($a['attribute'], $values($a), $a['distance'], $a['meters'] ?? false)),
    'query.distance_greater_than' => fn (array $a) => $q(Query::distanceGreaterThan($a['attribute'], $values($a), $a['distance'], $a['meters'] ?? false)),
    'query.distance_less_than' => fn (array $a) => $q(Query::distanceLessThan($a['attribute'], $values($a), $a['distance'], $a['meters'] ?? false)),
    'query.intersects' => fn (array $a) => $q(Query::intersects($a['attribute'], $values($a))),
    'query.not_intersects' => fn (array $a) => $q(Query::notIntersects($a['attribute'], $values($a))),
    'query.crosses' => fn (array $a) => $q(Query::crosses($a['attribute'], $values($a))),
    'query.not_crosses' => fn (array $a) => $q(Query::notCrosses($a['attribute'], $values($a))),
    'query.overlaps' => fn (array $a) => $q(Query::overlaps($a['attribute'], $values($a))),
    'query.not_overlaps' => fn (array $a) => $q(Query::notOverlaps($a['attribute'], $values($a))),
    'query.touches' => fn (array $a) => $q(Query::touches($a['attribute'], $values($a))),
    'query.not_touches' => fn (array $a) => $q(Query::notTouches($a['attribute'], $values($a))),
    'query.vector_dot' => fn (array $a) => $q(Query::vectorDot($a['attribute'], $values($a, 'vector'))),
    'query.vector_cosine' => fn (array $a) => $q(Query::vectorCosine($a['attribute'], $values($a, 'vector'))),
    'query.vector_euclidean' => fn (array $a) => $q(Query::vectorEuclidean($a['attribute'], $values($a, 'vector'))),
    'query.exists' => fn (array $a) => $q(Query::exists($values($a, 'attributes'))),
    'query.not_exists' => fn (array $a) => $q(Query::notExists($value($a, 'attribute'))),

    // Operator: construction, accessors and setters.
    'operator.new' => fn (array $a) => $o(new Operator($a['method'], $a['attribute'] ?? '', $values($a))),
    'operator.inspect' => function (array $a) use ($operator) {
        $x = $operator($a);

        return Values::encode([
            'method' => $x->getMethod(),
            'attribute' => $x->getAttribute(),
            'values' => $x->getValues(),
            'value' => $x->getValue(),
            'valueOr' => $x->getValue('default'),
            'isNumeric' => $x->isNumericOperation(),
            'isArray' => $x->isArrayOperation(),
            'isString' => $x->isStringOperation(),
            'isBoolean' => $x->isBooleanOperation(),
            'isDate' => $x->isDateOperation(),
        ]);
    },
    'operator.set' => function (array $a) use ($operator, $o, $values, $value) {
        $x = $operator($a);
        if (\array_key_exists('method', $a)) {
            $x->setMethod($a['method']);
        }
        if (\array_key_exists('attribute', $a)) {
            $x->setAttribute($a['attribute']);
        }
        if (\array_key_exists('values', $a)) {
            $x->setValues($values($a));
        }
        if (\array_key_exists('value', $a)) {
            $x->setValue($value($a));
        }

        return $o($x);
    },
    'operator.is_method' => fn (array $a) => Operator::isMethod($a['value']),
    'operator.is_operator' => fn (array $a) => Operator::isOperator($value($a)),
    'operator.constants' => fn (array $a) => [
        'types' => Operator::TYPES,
        'maxArrayOperatorSize' => Operator::MAX_ARRAY_OPERATOR_SIZE,
        'arrayFilterConditions' => Operator::ARRAY_FILTER_CONDITIONS,
    ],
    'operator.extract_operators' => fn (array $a) => Values::encode(Operator::extractOperators($values($a, 'data'))),

    // Operator: parsing and serialization.
    'operator.parse' => fn (array $a) => $o(Operator::parse($a['operator'])),
    'operator.parse_operator' => fn (array $a) => $o(Operator::parseOperator($values($a, 'operator'))),
    'operator.parse_operators' => fn (array $a) => Values::encode(Operator::parseOperators($a['operators'])),
    'operator.to_array' => fn (array $a) => Values::encode($operator($a)->toArray()),
    'operator.to_string' => fn (array $a) => $operator($a)->toString(),

    // Operator: static constructors.
    'operator.increment' => fn (array $a) => $o(Operator::increment($a['value'] ?? 1, $a['max'] ?? null)),
    'operator.decrement' => fn (array $a) => $o(Operator::decrement($a['value'] ?? 1, $a['min'] ?? null)),
    'operator.multiply' => fn (array $a) => $o(Operator::multiply($a['factor'], $a['max'] ?? null)),
    'operator.divide' => fn (array $a) => $o(Operator::divide($a['divisor'], $a['min'] ?? null)),
    'operator.modulo' => fn (array $a) => $o(Operator::modulo($a['divisor'])),
    'operator.power' => fn (array $a) => $o(Operator::power($a['exponent'], $a['max'] ?? null)),
    'operator.array_append' => fn (array $a) => $o(Operator::arrayAppend($values($a))),
    'operator.array_prepend' => fn (array $a) => $o(Operator::arrayPrepend($values($a))),
    'operator.array_insert' => fn (array $a) => $o(Operator::arrayInsert($a['index'], $value($a))),
    'operator.array_remove' => fn (array $a) => $o(Operator::arrayRemove($value($a))),
    'operator.array_unique' => fn (array $a) => $o(Operator::arrayUnique()),
    'operator.array_intersect' => fn (array $a) => $o(Operator::arrayIntersect($values($a))),
    'operator.array_diff' => fn (array $a) => $o(Operator::arrayDiff($values($a))),
    'operator.array_filter' => fn (array $a) => $o(Operator::arrayFilter($a['condition'], $value($a))),
    'operator.string_concat' => fn (array $a) => $o(Operator::stringConcat($value($a))),
    'operator.string_replace' => fn (array $a) => $o(Operator::stringReplace($a['search'], $a['replace'])),
    'operator.toggle' => fn (array $a) => $o(Operator::toggle()),
    'operator.date_add_days' => fn (array $a) => $o(Operator::dateAddDays($a['days'])),
    'operator.date_sub_days' => fn (array $a) => $o(Operator::dateSubDays($a['days'])),
    'operator.date_set_now' => fn (array $a) => $o(Operator::dateSetNow()),
];
