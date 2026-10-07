<?php

namespace Utopia\Database\Validator\Query;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\BigInt;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Sequence;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

class Filter extends Base
{
    /**
     * @var array<int|string, mixed>
     */
    protected array $schema = [];

    /**
     * @param array<Document> $attributes
     * @param int $maxValuesCount
     * @param \DateTime $minAllowedDate
     * @param \DateTime $maxAllowedDate
     */
    public function __construct(
        array $attributes,
        private readonly string $idAttributeType,
        private readonly int $maxValuesCount = 5000,
        private readonly \DateTime $minAllowedDate = new \DateTime('0000-01-01'),
        private readonly \DateTime $maxAllowedDate = new \DateTime('9999-12-31'),
        private readonly bool $supportForAttributes = true,
        private readonly bool $supportUnsignedBigInt = true
    ) {
        foreach ($attributes as $attribute) {
            $this->schema[$attribute->getAttribute('key', $attribute->getId())] = $attribute->getArrayCopy();
        }
    }

    /**
     * @param string $attribute
     * @return bool
     */
    protected function isValidAttribute(string $attribute): bool
    {
        if (
            \in_array('encrypt', $this->schema[$attribute]['filters'] ?? [])
        ) {
            $this->message = 'Cannot query encrypted attribute: ' . $attribute;
            return false;
        }

        if (\str_contains($attribute, '.')) {
            // Check for special symbol `.`
            if (isset($this->schema[$attribute])) {
                return true;
            }

            // For relationships, just validate the top level.
            // will validate each nested level during the recursive calls.
            $attribute = \explode('.', $attribute)[0];
        }

        // Search for attribute in schema
        if ($this->supportForAttributes && !isset($this->schema[$attribute])) {
            $this->message = 'Attribute not found in schema: ' . $attribute;
            return false;
        }

        return true;
    }

    /**
     * @param string $attribute
     * @param array<mixed> $values
     * @param string $method
     * @return bool
     */
    protected function isValidAttributeAndValues(string $attribute, array $values, string $method): bool
    {
        if (!$this->isValidAttribute($attribute)) {
            return false;
        }

        $originalAttribute = $attribute;
        // isset check if for special symbols "." in the attribute name
        // same for nested path on object
        if (\str_contains($attribute, '.') && !isset($this->schema[$attribute])) {
            // For relationships, just validate the top level.
            // Utopia will validate each nested level during the recursive calls.
            $attribute = \explode('.', $attribute)[0];
        }

        // exists and notExists queries don't require values, just attribute validation
        if (in_array($method, [Query::TYPE_EXISTS, Query::TYPE_NOT_EXISTS])) {
            // Validate attribute (handles encrypted attributes, schemaless mode, etc.)
            return $this->isValidAttribute($attribute);
        }

        if (!$this->supportForAttributes && !isset($this->schema[$attribute])) {
            // First check maxValuesCount guard for any IN-style value arrays
            if (count($values) > $this->maxValuesCount) {
                $this->message = 'Query on attribute has greater than ' . $this->maxValuesCount . ' values: ' . $attribute;
                return false;
            }

            return true;
        }
        $attributeSchema = $this->schema[$attribute];

        // Skip value validation for nested relationship queries (e.g., author.age)
        // The values will be validated when querying the related collection
        if ($attributeSchema['type'] === Database::VAR_RELATIONSHIP && $originalAttribute !== $attribute) {
            return true;
        }

        if (count($values) > $this->maxValuesCount) {
            $this->message = 'Query on attribute has greater than ' . $this->maxValuesCount . ' values: ' . $attribute;
            return false;
        }

        if (!$this->supportForAttributes && !isset($this->schema[$attribute])) {
            return true;
        }
        $attributeSchema = $this->schema[$attribute];

        $attributeType = $attributeSchema['type'];

        $isDottedOnObject = \str_contains($originalAttribute, '.') && $attributeType === Database::VAR_OBJECT;

        // If the query method is spatial-only, the attribute must be a spatial type
        $query = new Query($method);
        if ($query->isSpatialQuery() && !in_array($attributeType, Database::SPATIAL_TYPES, true)) {
            $this->message = 'Spatial query "' . $method . '" cannot be applied on non-spatial attribute: ' . $attribute;
            return false;
        }

        foreach ($values as $value) {
            $validator = null;

            switch ($attributeType) {
                case Database::VAR_ID:
                    $validator = new Sequence($this->idAttributeType, $attribute === '$sequence');
                    break;

                case Database::VAR_STRING:
                case Database::VAR_VARCHAR:
                case Database::VAR_TEXT:
                case Database::VAR_MEDIUMTEXT:
                case Database::VAR_LONGTEXT:
                    $validator = new Text(0, 0);
                    break;

                case Database::VAR_INTEGER:
                    $size = $attributeSchema['size'] ?? 4;
                    $signed = $attributeSchema['signed'] ?? true;
                    $bits = $size >= 8 ? 64 : 32;
                    // For 64-bit unsigned, use signed since PHP doesn't support true 64-bit unsigned
                    $unsigned = !$signed && $bits < 64;
                    $validator = new Integer(false, $bits, $unsigned);
                    break;

                case Database::VAR_BIGINT:
                    $signed = $attributeSchema['signed'] ?? true;
                    $validator = new BigInt($signed, $this->supportUnsignedBigInt);
                    break;

                case Database::VAR_FLOAT:
                    $validator = new FloatValidator();
                    break;

                case Database::VAR_BOOLEAN:
                    $validator = new Boolean();
                    break;

                case Database::VAR_DATETIME:
                    $validator = new DatetimeValidator(
                        min: $this->minAllowedDate,
                        max: $this->maxAllowedDate
                    );
                    break;

                case Database::VAR_RELATIONSHIP:
                    $validator = new Text(255, 0); // The query is always on uid
                    break;

                case Database::VAR_OBJECT:
                    // For dotted attributes on objects, validate as string (path queries)
                    if ($isDottedOnObject) {
                        $validator = new Text(0, 0);
                        break;
                    }

                    // object containment queries on the base object attribute
                    elseif (\in_array($method, [Query::TYPE_EQUAL, Query::TYPE_NOT_EQUAL, Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY, Query::TYPE_CONTAINS_ALL, Query::TYPE_NOT_CONTAINS], true)
                        && !$this->isValidObjectQueryValues($value)) {
                        $this->message = 'Invalid object query structure for attribute "' . $attribute . '"';
                        return false;
                    }

                    continue 2;
                case Database::VAR_POINT:
                case Database::VAR_LINESTRING:
                case Database::VAR_POLYGON:
                    if (!is_array($value)) {
                        $this->message = 'Spatial data must be an array';
                        return false;
                    }
                    continue 2;

                case Database::VAR_VECTOR:
                    // For vector queries, validate that the value is an array of floats
                    if (!is_array($value)) {
                        $this->message = 'Vector query value must be an array';
                        return false;
                    }
                    foreach ($value as $component) {
                        if (!is_numeric($component)) {
                            $this->message = 'Vector query value must contain only numeric values';
                            return false;
                        }
                    }
                    // Check size match
                    $expectedSize = $attributeSchema['size'] ?? 0;
                    if (count($value) !== $expectedSize) {
                        $this->message = "Vector query value must have {$expectedSize} elements";
                        return false;
                    }
                    continue 2;
                default:
                    $this->message = 'Unknown Data type';
                    return false;
            }

            if (!$validator->isValid($value)) {
                $this->message = 'Query value is invalid for attribute "' . $attribute . '"';
                return false;
            }
        }

        if ($attributeSchema['type'] === 'relationship') {
            /**
             * We can not disable relationship query since we have logic that use it,
             * so instead we validate against the relation type
             */
            $options = $attributeSchema['options'];

            if ($options['relationType'] === Database::RELATION_ONE_TO_ONE && $options['twoWay'] === false && $options['side'] === Database::RELATION_SIDE_CHILD) {
                $this->message = 'Cannot query on virtual relationship attribute';
                return false;
            }

            if ($options['relationType'] === Database::RELATION_ONE_TO_MANY && $options['side'] === Database::RELATION_SIDE_PARENT) {
                $this->message = 'Cannot query on virtual relationship attribute';
                return false;
            }

            if ($options['relationType'] === Database::RELATION_MANY_TO_ONE && $options['side'] === Database::RELATION_SIDE_CHILD) {
                $this->message = 'Cannot query on virtual relationship attribute';
                return false;
            }

            if ($options['relationType'] === Database::RELATION_MANY_TO_MANY) {
                $this->message = 'Cannot query on virtual relationship attribute';
                return false;
            }
        }

        $array = $attributeSchema['array'] ?? false;

        if (
            !$array &&
            in_array($method, [Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY, Query::TYPE_CONTAINS_ALL, Query::TYPE_NOT_CONTAINS]) &&
            !in_array($attributeSchema['type'], Database::STRING_TYPES) &&
            $attributeSchema['type'] !== Database::VAR_OBJECT &&
            !in_array($attributeSchema['type'], Database::SPATIAL_TYPES)
        ) {
            $queryType = $method === Query::TYPE_NOT_CONTAINS ? 'notContains' : 'contains';
            $this->message = 'Cannot query ' . $queryType . ' on attribute "' . $attribute . '" because it is not an array, string, or object.';
            return false;
        }

        if (
            $array &&
            !in_array($method, [Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY, Query::TYPE_CONTAINS_ALL, Query::TYPE_NOT_CONTAINS, Query::TYPE_IS_NULL, Query::TYPE_IS_NOT_NULL, Query::TYPE_EXISTS, Query::TYPE_NOT_EXISTS])
        ) {
            $this->message = 'Cannot query '. $method .' on attribute "' . $attribute . '" because it is an array.';
            return false;
        }

        // Vector queries can only be used on vector attributes (not arrays)
        if (\in_array($method, Query::VECTOR_TYPES)) {
            if ($attributeSchema['type'] !== Database::VAR_VECTOR) {
                $this->message = 'Vector queries can only be used on vector attributes';
                return false;
            }
            if ($array) {
                $this->message = 'Vector queries cannot be used on array attributes';
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $values
     * @return bool
     */
    protected function isEmpty(array $values): bool
    {
        if (count($values) === 0) {
            return true;
        }

        if (is_array($values[0]) && count($values[0]) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Validate object attribute query values.
     *
     * Disallows ambiguous nested structures like:
     *   ['a' => [1, 'b' => [212]]]           // mixed list
     *
     * but allows:
     *   ['a' => [1, 2], 'b' => [212]]        // multiple top-level paths
     *   ['projects' => [[...]]]              // list of objects
     *   ['role' => ['name' => [...], 'ex' => [...]]]  // multiple nested paths
     *
     * @param mixed $values
     * @return bool
     */
    private function isValidObjectQueryValues(mixed $values): bool
    {
        if (!is_array($values)) {
            return true;
        }

        $hasInt = false;
        $hasString = false;

        foreach (array_keys($values) as $key) {
            if (is_int($key)) {
                $hasInt = true;
            } else {
                $hasString = true;
            }
        }

        if ($hasInt && $hasString) {
            return false;
        }

        foreach ($values as $value) {
            if (!$this->isValidObjectQueryValues($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is valid.
     *
     * Returns true if method is a filter method, attribute exists, and value matches attribute type
     *
     * Otherwise, returns false
     *
     * @param Query $value
     * @return bool
     */
    public function isValid($value): bool
    {
        $method = $value->getMethod();
        $attribute = $value->getAttribute();
        switch ($method) {
            case Query::TYPE_EQUAL:
            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
            case Query::TYPE_NOT_CONTAINS:
            case Query::TYPE_CONTAINS_ALL:
            case Query::TYPE_EXISTS:
            case Query::TYPE_NOT_EXISTS:
                if ($this->isEmpty($value->getValues())) {
                    $this->message = \ucfirst($method) . ' queries require at least one value.';
                    return false;
                }

                return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);

            case Query::TYPE_DISTANCE_EQUAL:
            case Query::TYPE_DISTANCE_NOT_EQUAL:
            case Query::TYPE_DISTANCE_GREATER_THAN:
            case Query::TYPE_DISTANCE_LESS_THAN:
                if (count($value->getValues()) !== 1 || !is_array($value->getValues()[0]) || count($value->getValues()[0]) !== 3) {
                    $this->message = 'Distance query requires [[geometry, distance]] parameters';
                    return false;
                }
                return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);

            case Query::TYPE_NOT_EQUAL:
            case Query::TYPE_LESSER:
            case Query::TYPE_LESSER_EQUAL:
            case Query::TYPE_GREATER:
            case Query::TYPE_GREATER_EQUAL:
            case Query::TYPE_SEARCH:
            case Query::TYPE_NOT_SEARCH:
            case Query::TYPE_STARTS_WITH:
            case Query::TYPE_NOT_STARTS_WITH:
            case Query::TYPE_ENDS_WITH:
            case Query::TYPE_NOT_ENDS_WITH:
            case Query::TYPE_REGEX:
                if (count($value->getValues()) != 1) {
                    $this->message = \ucfirst($method) . ' queries require exactly one value.';
                    return false;
                }

                return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);

            case Query::TYPE_BETWEEN:
            case Query::TYPE_NOT_BETWEEN:
                if (count($value->getValues()) != 2) {
                    $this->message = \ucfirst($method) . ' queries require exactly two values.';
                    return false;
                }

                return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);

            case Query::TYPE_IS_NULL:
            case Query::TYPE_IS_NOT_NULL:
                return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);

            case Query::TYPE_VECTOR_DOT:
            case Query::TYPE_VECTOR_COSINE:
            case Query::TYPE_VECTOR_EUCLIDEAN:
                // Validate that the attribute is a vector type
                if (!$this->isValidAttribute($attribute)) {
                    return false;
                }

                // Handle dotted attributes (relationships)
                $attributeKey = $attribute;
                if (\str_contains($attributeKey, '.') && !isset($this->schema[$attributeKey])) {
                    $attributeKey = \explode('.', $attributeKey)[0];
                }

                $attributeSchema = $this->schema[$attributeKey];
                if ($attributeSchema['type'] !== Database::VAR_VECTOR) {
                    $this->message = 'Vector queries can only be used on vector attributes';
                    return false;
                }

                if (count($value->getValues()) != 1) {
                    $this->message = \ucfirst($method) . ' queries require exactly one vector value.';
                    return false;
                }

                return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);
            case Query::TYPE_OR:
            case Query::TYPE_AND:
                $filters = Query::groupByType($value->getValues())['filters'];

                if (count($value->getValues()) !== count($filters)) {
                    $this->message = \ucfirst($method) . ' queries can only contain filter queries';
                    return false;
                }

                if (count($filters) < 2) {
                    $this->message = \ucfirst($method) . ' queries require at least two queries';
                    return false;
                }

                return true;

            case Query::TYPE_ELEM_MATCH:
                // elemMatch is not supported when adapter supports attributes (schema mode)
                if ($this->supportForAttributes) {
                    $this->message = 'elemMatch is not supported by the database';
                    return false;
                }

                // Validate that the attribute (array field) exists
                if (!$this->isValidAttribute($attribute)) {
                    return false;
                }

                // For schemaless mode, allow elemMatch on any attribute
                // Validate nested queries are filter queries
                $filters = Query::groupByType($value->getValues())['filters'];
                if (count($value->getValues()) !== count($filters)) {
                    $this->message = 'elemMatch queries can only contain filter queries';
                    return false;
                }

                if (count($filters) < 1) {
                    $this->message = 'elemMatch queries require at least one query';
                    return false;
                }
                return true;

            default:
                // Handle spatial query types and any other query types
                if ($value->isSpatialQuery()) {
                    if ($this->isEmpty($value->getValues())) {
                        $this->message = \ucfirst($method) . ' queries require at least one value.';
                        return false;
                    }
                    return $this->isValidAttributeAndValues($attribute, $value->getValues(), $method);
                }

                return false;
        }
    }

    public function getMaxValuesCount(): int
    {
        return $this->maxValuesCount;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_FILTER;
    }
}
