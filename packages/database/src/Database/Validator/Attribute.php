<?php

namespace Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Validator;

class Attribute extends Validator
{
    protected string $message = 'Invalid attribute';

    /**
     * @var array<Document> $attributes
     */
    protected array $attributes = [];

    /**
     * @var array<Document> $schemaAttributes
     */
    protected array $schemaAttributes = [];

    /**
     * @param array<Document> $attributes
     * @param array<Document> $schemaAttributes
     * @param int $maxAttributes
     * @param int $maxWidth
     * @param int $maxStringLength
     * @param int $maxVarcharLength
     * @param int $maxIntLength
     * @param int $maxBigIntLength
     * @param bool $supportForSchemaAttributes
     * @param bool $supportForVectors
     * @param bool $supportForSpatialAttributes
     * @param bool $supportForObject
     * @param callable|null $attributeCountCallback
     * @param callable|null $attributeWidthCallback
     * @param callable|null $filterCallback
     * @param bool $isMigrating
     * @param bool $sharedTables
     */
    public function __construct(
        array $attributes,
        array $schemaAttributes = [],
        protected int $maxAttributes = 0,
        protected int $maxWidth = 0,
        protected int $maxStringLength = 0,
        protected int $maxVarcharLength = 0,
        protected int $maxIntLength = 0,
        protected int $maxBigIntLength = 0,
        protected bool $supportForSchemaAttributes = false,
        protected bool $supportForVectors = false,
        protected bool $supportForSpatialAttributes = false,
        protected bool $supportForObject = false,
        protected bool $supportUnsignedBigInt = false,
        protected mixed $attributeCountCallback = null,
        protected mixed $attributeWidthCallback = null,
        protected mixed $filterCallback = null,
        protected bool $isMigrating = false,
        protected bool $sharedTables = false,
    ) {
        // Keep backwards compatibility for existing validator construction sites.
        if ($this->maxBigIntLength === 0) {
            $this->maxBigIntLength = $this->maxIntLength;
        }

        foreach ($attributes as $attribute) {
            $key = \strtolower($attribute->getAttribute('key', $attribute->getAttribute('$id')));
            $this->attributes[$key] = $attribute;
        }
        foreach ($schemaAttributes as $attribute) {
            $key = \strtolower($attribute->getAttribute('key', $attribute->getAttribute('$id')));
            $this->schemaAttributes[$key] = $attribute;
        }
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }

    /**
     * Returns validator description
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Is valid.
     *
     * Returns true if attribute is valid.
     * @param Document $value
     * @return bool
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     */
    public function isValid($value): bool
    {
        if (!$this->checkDuplicateId($value)) {
            return false;
        }
        if (!$this->checkDuplicateInSchema($value)) {
            return false;
        }
        if (!$this->checkRequiredFilters($value)) {
            return false;
        }
        if (!$this->checkFormat($value)) {
            return false;
        }
        if (!$this->checkAttributeLimits($value)) {
            return false;
        }
        if (!$this->checkType($value)) {
            return false;
        }
        if (!$this->checkDefaultValue($value)) {
            return false;
        }

        return true;
    }

    /**
     * Check for duplicate attribute ID in collection metadata
     *
     * @param Document $attribute
     * @return bool
     * @throws DuplicateException
     */
    public function checkDuplicateId(Document $attribute): bool
    {
        $id = $attribute->getAttribute('key', $attribute->getAttribute('$id'));

        foreach ($this->attributes as $existingAttribute) {
            if (\strtolower($existingAttribute->getId()) === \strtolower($id)) {
                $this->message = 'Attribute already exists in metadata';
                throw new DuplicateException($this->message);
            }
        }

        return true;
    }

    /**
     * Check for duplicate attribute ID in schema
     *
     * @param Document $attribute
     * @return bool
     * @throws DuplicateException
     */
    public function checkDuplicateInSchema(Document $attribute): bool
    {
        if (!$this->supportForSchemaAttributes) {
            return true;
        }

        if ($this->sharedTables && $this->isMigrating) {
            return true;
        }

        $id = $attribute->getAttribute('key', $attribute->getAttribute('$id'));

        foreach ($this->schemaAttributes as $schemaAttribute) {
            $schemaId = $this->filterCallback ? ($this->filterCallback)($schemaAttribute->getId()) : $schemaAttribute->getId();
            if (\strtolower($schemaId) === \strtolower($id)) {
                $this->message = 'Attribute already exists in schema';
                throw new DuplicateException($this->message);
            }
        }

        return true;
    }

    /**
     * Check if required filters are present for the attribute type
     *
     * @param Document $attribute
     * @return bool
     * @throws DatabaseException
     */
    public function checkRequiredFilters(Document $attribute): bool
    {
        $type = $attribute->getAttribute('type');
        $filters = $attribute->getAttribute('filters', []);

        $requiredFilters = $this->getRequiredFilters($type);
        if (!empty(\array_diff($requiredFilters, $filters))) {
            $this->message = "Attribute of type: $type requires the following filters: " . implode(",", $requiredFilters);
            throw new DatabaseException($this->message);
        }

        return true;
    }

    /**
     * Get the list of required filters for each data type
     *
     * @param string|null $type Type of the attribute
     *
     * @return array<string>
     */
    protected function getRequiredFilters(?string $type): array
    {
        return match ($type) {
            Database::VAR_DATETIME => ['datetime'],
            default => [],
        };
    }

    /**
     * Check if format is valid for the attribute type
     *
     * @param Document $attribute
     * @return bool
     * @throws DatabaseException
     */
    public function checkFormat(Document $attribute): bool
    {
        $format = $attribute->getAttribute('format');
        $type = $attribute->getAttribute('type');

        if ($format && !Structure::hasFormat($format, $type)) {
            $this->message = 'Format ("' . $format . '") not available for this attribute type ("' . $type . '")';
            throw new DatabaseException($this->message);
        }

        return true;
    }

    /**
     * Check attribute limits (count and width)
     *
     * @param Document $attribute
     * @return bool
     * @throws LimitException
     */
    public function checkAttributeLimits(Document $attribute): bool
    {
        if ($this->attributeCountCallback === null || $this->attributeWidthCallback === null) {
            return true;
        }

        $attributeCount = ($this->attributeCountCallback)($attribute);
        $attributeWidth = ($this->attributeWidthCallback)($attribute);

        if ($this->maxAttributes > 0 && $attributeCount > $this->maxAttributes) {
            $this->message = 'Column limit reached. Cannot create new attribute. Current attribute count is ' . $attributeCount . ' but the maximum is ' . $this->maxAttributes . '. Remove some attributes to free up space.';
            throw new LimitException($this->message);
        }

        if ($this->maxWidth > 0 && $attributeWidth >= $this->maxWidth) {
            $this->message = 'Row width limit reached. Cannot create new attribute. Current row width is ' . $attributeWidth . ' bytes but the maximum is ' . $this->maxWidth . ' bytes. Reduce the size of existing attributes or remove some attributes to free up space.';
            throw new LimitException($this->message);
        }

        return true;
    }

    /**
     * Check attribute type and type-specific constraints
     *
     * @param Document $attribute
     * @return bool
     * @throws DatabaseException
     */
    public function checkType(Document $attribute): bool
    {
        $type = $attribute->getAttribute('type');
        $size = $attribute->getAttribute('size', 0);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $default = $attribute->getAttribute('default');

        switch ($type) {
            case Database::VAR_ID:
                break;

            case Database::VAR_STRING:
                if ($size > $this->maxStringLength) {
                    $this->message = 'Max size allowed for string is: ' . number_format($this->maxStringLength);
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_VARCHAR:
                if ($size > $this->maxVarcharLength) {
                    $this->message = 'Max size allowed for varchar is: ' . number_format($this->maxVarcharLength);
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_TEXT:
                if ($size > Database::MAX_TEXT_BYTES) {
                    $this->message = 'Max size allowed for text is: ' . Database::MAX_TEXT_BYTES;
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_MEDIUMTEXT:
                if ($size > Database::MAX_MEDIUMTEXT_BYTES) {
                    $this->message = 'Max size allowed for mediumtext is: ' . Database::MAX_MEDIUMTEXT_BYTES;
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_LONGTEXT:
                if ($size > Database::MAX_LONGTEXT_BYTES) {
                    $this->message = 'Max size allowed for longtext is: ' . Database::MAX_LONGTEXT_BYTES;
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_INTEGER:
                $limit = ($signed) ? $this->maxIntLength / 2 : $this->maxIntLength;
                if ($size > $limit) {
                    $this->message = 'Max size allowed for int is: ' . number_format($limit);
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_BIGINT:
                break;

            case Database::VAR_FLOAT:
            case Database::VAR_BOOLEAN:
            case Database::VAR_DATETIME:
            case Database::VAR_RELATIONSHIP:
                break;

            case Database::VAR_OBJECT:
                if (!$this->supportForObject) {
                    $this->message = 'Object attributes are not supported';
                    throw new DatabaseException($this->message);
                }
                if (!empty($size)) {
                    $this->message = 'Size must be empty for object attributes';
                    throw new DatabaseException($this->message);
                }
                if (!empty($array)) {
                    $this->message = 'Object attributes cannot be arrays';
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_POINT:
            case Database::VAR_LINESTRING:
            case Database::VAR_POLYGON:
                if (!$this->supportForSpatialAttributes) {
                    $this->message = 'Spatial attributes are not supported';
                    throw new DatabaseException($this->message);
                }
                if (!empty($size)) {
                    $this->message = 'Size must be empty for spatial attributes';
                    throw new DatabaseException($this->message);
                }
                if (!empty($array)) {
                    $this->message = 'Spatial attributes cannot be arrays';
                    throw new DatabaseException($this->message);
                }
                break;

            case Database::VAR_VECTOR:
                if (!$this->supportForVectors) {
                    $this->message = 'Vector types are not supported by the current database';
                    throw new DatabaseException($this->message);
                }
                if ($array) {
                    $this->message = 'Vector type cannot be an array';
                    throw new DatabaseException($this->message);
                }
                if ($size <= 0) {
                    $this->message = 'Vector dimensions must be a positive integer';
                    throw new DatabaseException($this->message);
                }
                if ($size > Database::MAX_VECTOR_DIMENSIONS) {
                    $this->message = 'Vector dimensions cannot exceed ' . Database::MAX_VECTOR_DIMENSIONS;
                    throw new DatabaseException($this->message);
                }

                // Validate default value if provided
                if ($default !== null) {
                    if (!is_array($default)) {
                        $this->message = 'Vector default value must be an array';
                        throw new DatabaseException($this->message);
                    }
                    if (count($default) !== $size) {
                        $this->message = 'Vector default value must have exactly ' . $size . ' elements';
                        throw new DatabaseException($this->message);
                    }
                    foreach ($default as $component) {
                        if (!is_numeric($component)) {
                            $this->message = 'Vector default value must contain only numeric elements';
                            throw new DatabaseException($this->message);
                        }
                    }
                }
                break;

            default:
                $supportedTypes = [
                    Database::VAR_STRING,
                    Database::VAR_VARCHAR,
                    Database::VAR_TEXT,
                    Database::VAR_MEDIUMTEXT,
                    Database::VAR_LONGTEXT,
                    Database::VAR_INTEGER,
                    Database::VAR_BIGINT,
                    Database::VAR_FLOAT,
                    Database::VAR_BOOLEAN,
                    Database::VAR_DATETIME,
                    Database::VAR_RELATIONSHIP
                ];
                if ($this->supportForVectors) {
                    $supportedTypes[] = Database::VAR_VECTOR;
                }
                if ($this->supportForSpatialAttributes) {
                    \array_push($supportedTypes, ...Database::SPATIAL_TYPES);
                }
                if ($this->supportForObject) {
                    $supportedTypes[] = Database::VAR_OBJECT;
                }
                $this->message = 'Unknown attribute type: ' . $type . '. Must be one of ' . implode(', ', $supportedTypes);
                throw new DatabaseException($this->message);
        }

        return true;
    }

    /**
     * Check default value constraints and type matching
     *
     * @param Document $attribute
     * @return bool
     * @throws DatabaseException
     */
    public function checkDefaultValue(Document $attribute): bool
    {
        $default = $attribute->getAttribute('default');
        $required = $attribute->getAttribute('required', false);
        $type = $attribute->getAttribute('type');
        $array = $attribute->getAttribute('array', false);
        $signed = $attribute->getAttribute('signed', true);

        if (\is_null($default)) {
            return true;
        }

        if ($required === true) {
            $this->message = 'Cannot set a default value for a required attribute';
            throw new DatabaseException($this->message);
        }

        // Reject array defaults for non-array attributes (except vectors, spatial types, and objects which use arrays internally)
        if (\is_array($default) && !$array && !\in_array($type, [Database::VAR_VECTOR, Database::VAR_OBJECT, ...Database::SPATIAL_TYPES], true)) {
            $this->message = 'Cannot set an array default value for a non-array attribute';
            throw new DatabaseException($this->message);
        }

        $this->validateDefaultTypes($type, $default, $signed);

        return true;
    }

    /**
     * Function to validate if the default value of an attribute matches its attribute type
     *
     * @param string $type Type of the attribute
     * @param mixed $default Default value of the attribute
     * @param bool $signed Whether the attribute is signed (relevant for bigint)
     *
     * @return void
     * @throws DatabaseException
     */
    protected function validateDefaultTypes(string $type, mixed $default, bool $signed = true): void
    {
        $defaultType = \gettype($default);

        if ($defaultType === 'NULL') {
            // Disable null. No validation required
            return;
        }

        if ($defaultType === 'array') {
            // Spatial types require the array itself
            if (!in_array($type, Database::SPATIAL_TYPES) && $type != Database::VAR_OBJECT) {
                foreach ($default as $value) {
                    $this->validateDefaultTypes($type, $value, $signed);
                }
            }
            return;
        }

        switch ($type) {
            case Database::VAR_STRING:
            case Database::VAR_VARCHAR:
            case Database::VAR_TEXT:
            case Database::VAR_MEDIUMTEXT:
            case Database::VAR_LONGTEXT:
                if ($defaultType !== 'string') {
                    $this->message = 'Default value ' . $default . ' does not match given type ' . $type;
                    throw new DatabaseException($this->message);
                }
                break;
            case Database::VAR_INTEGER:
            case Database::VAR_FLOAT:
            case Database::VAR_BOOLEAN:
                if ($type !== $defaultType) {
                    $this->message = 'Default value ' . $default . ' does not match given type ' . $type;
                    throw new DatabaseException($this->message);
                }
                break;
            case Database::VAR_BIGINT:
                if ($defaultType !== 'integer' && $defaultType !== 'string') {
                    $this->message = 'Default value ' . $default . ' does not match given type ' . $type;
                    throw new DatabaseException($this->message);
                }
                if ($defaultType === 'string' && !BigInt::isIntegerString($default, $signed)) {
                    $this->message = 'Default value ' . $default . ' is not a valid integer string for type bigint';
                    throw new DatabaseException($this->message);
                }
                break;
            case Database::VAR_DATETIME:
                if ($defaultType !== Database::VAR_STRING) {
                    $this->message = 'Default value ' . $default . ' does not match given type ' . $type;
                    throw new DatabaseException($this->message);
                }
                break;
            case Database::VAR_VECTOR:
                // When validating individual vector components (from recursion), they should be numeric
                if ($defaultType !== 'double' && $defaultType !== 'integer') {
                    $this->message = 'Vector components must be numeric values (float or integer)';
                    throw new DatabaseException($this->message);
                }
                break;
            default:
                $supportedTypes = [
                    Database::VAR_STRING,
                    Database::VAR_VARCHAR,
                    Database::VAR_TEXT,
                    Database::VAR_MEDIUMTEXT,
                    Database::VAR_LONGTEXT,
                    Database::VAR_INTEGER,
                    Database::VAR_BIGINT,
                    Database::VAR_FLOAT,
                    Database::VAR_BOOLEAN,
                    Database::VAR_DATETIME,
                    Database::VAR_RELATIONSHIP
                ];
                if ($this->supportForVectors) {
                    $supportedTypes[] = Database::VAR_VECTOR;
                }
                if ($this->supportForSpatialAttributes) {
                    \array_push($supportedTypes, ...Database::SPATIAL_TYPES);
                }
                $this->message = 'Unknown attribute type: ' . $type . '. Must be one of ' . implode(', ', $supportedTypes);
                throw new DatabaseException($this->message);
        }
    }
}
