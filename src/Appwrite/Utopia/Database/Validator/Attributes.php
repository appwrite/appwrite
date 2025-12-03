<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Validator;

class Attributes extends Validator
{
    protected int $maxAttributes;
    protected string $message = 'Invalid attributes';

    /**
     * @var array<string> Supported attribute types
     */
    protected array $supportedTypes = [
        Database::VAR_STRING,
        Database::VAR_INTEGER,
        Database::VAR_FLOAT,
        Database::VAR_BOOLEAN,
        Database::VAR_DATETIME,
        Database::VAR_RELATIONSHIP,
        Database::VAR_POINT,
        Database::VAR_LINESTRING,
        Database::VAR_POLYGON,
    ];

    /**
     * @var array<string> Supported formats for string attributes
     */
    protected array $supportedFormats = [
        '',
        APP_DATABASE_ATTRIBUTE_EMAIL,
        APP_DATABASE_ATTRIBUTE_ENUM,
        APP_DATABASE_ATTRIBUTE_IP,
        APP_DATABASE_ATTRIBUTE_URL,
    ];

    /**
     * @param int $maxAttributes Maximum number of attributes allowed
     */
    public function __construct(int $maxAttributes = APP_LIMIT_ARRAY_PARAMS_SIZE)
    {
        $this->maxAttributes = $maxAttributes;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /**
     * Is valid
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!is_array($value)) {
            $this->message = 'Attributes must be an array';
            return false;
        }

        if (count($value) > $this->maxAttributes) {
            $this->message = 'Maximum of ' . $this->maxAttributes . ' attributes allowed';
            return false;
        }

        $keyValidator = new Key();
        $keys = [];

        foreach ($value as $index => $attribute) {
            if (!is_array($attribute)) {
                $this->message = "Attribute at index $index must be an object";
                return false;
            }

            // Validate required fields
            if (!isset($attribute['key'])) {
                $this->message = "Attribute at index $index is missing required field 'key'";
                return false;
            }

            if (!isset($attribute['type'])) {
                $this->message = "Attribute at index $index is missing required field 'type'";
                return false;
            }

            // Validate key
            if (!$keyValidator->isValid($attribute['key'])) {
                $this->message = "Invalid key for attribute at index $index: " . $keyValidator->getDescription();
                return false;
            }

            // Check for duplicate keys
            if (in_array($attribute['key'], $keys)) {
                $this->message = "Duplicate attribute key: " . $attribute['key'];
                return false;
            }
            $keys[] = $attribute['key'];

            // Validate type
            if (!in_array($attribute['type'], $this->supportedTypes)) {
                $this->message = "Invalid type for attribute '" . $attribute['key'] . "': " . $attribute['type'];
                return false;
            }

            // Validate size for string types
            if ($attribute['type'] === Database::VAR_STRING) {
                if (!isset($attribute['size']) || !is_int($attribute['size']) || $attribute['size'] < 1 || $attribute['size'] > APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH) {
                    $this->message = "Invalid or missing size for string attribute '" . $attribute['key'] . "'. Size must be between 1 and " . APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH;
                    return false;
                }
            }

            // Validate format if provided
            if (isset($attribute['format']) && $attribute['format'] !== '') {
                if (!in_array($attribute['format'], $this->supportedFormats)) {
                    $this->message = "Invalid format for attribute '" . $attribute['key'] . "': " . $attribute['format'];
                    return false;
                }
            }

            // Validate required field if provided
            if (isset($attribute['required']) && !is_bool($attribute['required'])) {
                $this->message = "Invalid 'required' value for attribute '" . $attribute['key'] . "': must be a boolean";
                return false;
            }

            // Validate array field if provided
            if (isset($attribute['array']) && !is_bool($attribute['array'])) {
                $this->message = "Invalid 'array' value for attribute '" . $attribute['key'] . "': must be a boolean";
                return false;
            }

            // Validate signed field if provided
            if (isset($attribute['signed']) && !is_bool($attribute['signed'])) {
                $this->message = "Invalid 'signed' value for attribute '" . $attribute['key'] . "': must be a boolean";
                return false;
            }

            // Validate required and default conflict
            if (isset($attribute['required']) && $attribute['required'] === true && isset($attribute['default']) && $attribute['default'] !== null) {
                $this->message = "Attribute '" . $attribute['key'] . "' cannot have a default value when required is true";
                return false;
            }

            // Validate array and default conflict
            if (isset($attribute['array']) && $attribute['array'] === true && isset($attribute['default']) && $attribute['default'] !== null) {
                $this->message = "Attribute '" . $attribute['key'] . "' cannot have a default value when array is true";
                return false;
            }

            // Validate enum elements if format is enum
            if (isset($attribute['format']) && $attribute['format'] === APP_DATABASE_ATTRIBUTE_ENUM) {
                if (!isset($attribute['elements']) || !is_array($attribute['elements']) || empty($attribute['elements'])) {
                    $this->message = "Attribute '" . $attribute['key'] . "' with enum format must have 'elements' array";
                    return false;
                }
            }

            // Validate relationship options
            if ($attribute['type'] === Database::VAR_RELATIONSHIP) {
                if (!isset($attribute['relatedCollection']) || empty($attribute['relatedCollection'])) {
                    $this->message = "Relationship attribute '" . $attribute['key'] . "' must have 'relatedCollection'";
                    return false;
                }
                if (!isset($attribute['relationType']) || !in_array($attribute['relationType'], [
                    Database::RELATION_ONE_TO_ONE,
                    Database::RELATION_ONE_TO_MANY,
                    Database::RELATION_MANY_TO_ONE,
                    Database::RELATION_MANY_TO_MANY,
                ])) {
                    $this->message = "Relationship attribute '" . $attribute['key'] . "' must have valid 'relationType'";
                    return false;
                }

                // Validate twoWay if provided
                if (isset($attribute['twoWay']) && !is_bool($attribute['twoWay'])) {
                    $this->message = "Invalid 'twoWay' value for relationship attribute '" . $attribute['key'] . "': must be a boolean";
                    return false;
                }

                // Validate twoWayKey if provided
                if (isset($attribute['twoWayKey']) && !empty($attribute['twoWayKey'])) {
                    if (!$keyValidator->isValid($attribute['twoWayKey'])) {
                        $this->message = "Invalid 'twoWayKey' for relationship attribute '" . $attribute['key'] . "': " . $keyValidator->getDescription();
                        return false;
                    }
                }

                // Validate onDelete if provided
                if (isset($attribute['onDelete']) && !in_array($attribute['onDelete'], [
                    Database::RELATION_MUTATE_CASCADE,
                    Database::RELATION_MUTATE_RESTRICT,
                    Database::RELATION_MUTATE_SET_NULL,
                ])) {
                    $this->message = "Invalid 'onDelete' value for relationship attribute '" . $attribute['key'] . "': must be 'cascade', 'restrict', or 'setNull'";
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Is array
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }
}
