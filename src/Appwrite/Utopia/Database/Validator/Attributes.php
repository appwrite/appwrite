<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Key;
use Utopia\Emails\Validator\Email;
use Utopia\Validator;
use Utopia\Validator\IP;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

class Attributes extends Validator
{
    protected int $maxAttributes;
    protected string $message = 'Invalid attributes';

    /**
     * @var array<string> Supported attribute types
     */
    protected array $supportedTypes = [
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
     * Format aliases accepted as `type` for createTable/createCollection inline columns.
     * These map to string + format (matching dedicated createEmailColumn etc.).
     *
     * @var array<string, array{type: string, format: string, size: int}>
     */
    protected const FORMAT_ALIASES = [
        APP_DATABASE_ATTRIBUTE_EMAIL => [
            'type' => Database::VAR_STRING,
            'format' => APP_DATABASE_ATTRIBUTE_EMAIL,
            'size' => 254,
        ],
        APP_DATABASE_ATTRIBUTE_URL => [
            'type' => Database::VAR_STRING,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
            'size' => 2000,
        ],
        APP_DATABASE_ATTRIBUTE_IP => [
            'type' => Database::VAR_STRING,
            'format' => APP_DATABASE_ATTRIBUTE_IP,
            'size' => 39,
        ],
        APP_DATABASE_ATTRIBUTE_ENUM => [
            'type' => Database::VAR_STRING,
            'format' => APP_DATABASE_ATTRIBUTE_ENUM,
            'size' => Database::LENGTH_KEY,
        ],
    ];

    /**
     * Default sizes for utopia string subtypes when omitted from inline column defs.
     *
     * @var array<string, int>
     */
    protected const STRING_SUBTYPE_DEFAULT_SIZES = [
        Database::VAR_TEXT => 65535,
        Database::VAR_MEDIUMTEXT => 16777215,
        Database::VAR_LONGTEXT => 2147483647,
    ];

    /**
     * @param int $maxAttributes Maximum number of attributes allowed
     * @param bool $supportForSpatialAttributes Whether DB supports spatial attributes
     * @param bool $supportForAttributes Whether DB supports attributes or not
     */
    public function __construct(
        int $maxAttributes = APP_LIMIT_ARRAY_PARAMS_SIZE,
        protected bool $supportForSpatialAttributes = true,
        protected bool $supportForAttributes = true
    ) {
        $this->maxAttributes = $maxAttributes;
    }

    /**
     * Normalize inline attribute/column definitions so docs-friendly aliases
     * (email, url, ip, enum, text, …) match the storage model used by dedicated
     * create*Column endpoints.
     *
     * @param array<int, mixed> $attributes
     * @return array<int, mixed>
     */
    public static function normalize(array $attributes): array
    {
        foreach ($attributes as $index => $attribute) {
            if (!\is_array($attribute)) {
                continue;
            }
            $attributes[$index] = self::normalizeAttribute($attribute);
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    public static function normalizeAttribute(array $attribute): array
    {
        $type = $attribute['type'] ?? null;
        if (!\is_string($type) || $type === '') {
            return $attribute;
        }

        if (isset(self::FORMAT_ALIASES[$type])) {
            $alias = self::FORMAT_ALIASES[$type];
            $attribute['type'] = $alias['type'];
            $attribute['format'] = $attribute['format'] ?? $alias['format'];
            if (!isset($attribute['size'])) {
                $attribute['size'] = $alias['size'];
            }
            return $attribute;
        }

        if (isset(self::STRING_SUBTYPE_DEFAULT_SIZES[$type]) && !isset($attribute['size'])) {
            $attribute['size'] = self::STRING_SUBTYPE_DEFAULT_SIZES[$type];
        }

        return $attribute;
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
        if (!\is_array($value)) {
            $this->message = 'Attributes must be an array';
            return false;
        }

        if (\count($value) && !$this->supportForAttributes) {
            $this->message = 'Attributes are not supported by the current database';
            return false;
        }

        if (\count($value) > $this->maxAttributes) {
            $this->message = 'Maximum of ' . $this->maxAttributes . ' attributes allowed';
            return false;
        }

        $keyValidator = new Key();
        $keys = [];

        foreach ($value as $index => $attribute) {
            if (!\is_array($attribute)) {
                $this->message = "Attribute at index $index must be an object";
                return false;
            }

            $attribute = self::normalizeAttribute($attribute);

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

            // Check for reserved keys
            $reservedKeys = ['$id', '$createdAt', '$updatedAt', '$permissions', '$collection'];
            if (\in_array($attribute['key'], $reservedKeys)) {
                $this->message = "Attribute key '" . $attribute['key'] . "' is reserved and cannot be used";
                return false;
            }

            // Validate type
            if (!\in_array($attribute['type'], $this->supportedTypes)) {
                $this->message = "Invalid type for attribute '" . $attribute['key'] . "': " . $attribute['type'];
                return false;
            }

            // Validate spatial type support
            if (\in_array($attribute['type'], Database::SPATIAL_TYPES) && !$this->supportForSpatialAttributes) {
                $this->message = "Spatial attributes are not supported by the current database";
                return false;
            }

            // Validate size for string types (string/varchar require size; text subtypes get defaults via normalize)
            if (\in_array($attribute['type'], Database::STRING_TYPES, true)) {
                if (!isset($attribute['size']) || !is_int($attribute['size']) || $attribute['size'] < 1 || $attribute['size'] > APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH) {
                    $this->message = "Invalid or missing size for string attribute '" . $attribute['key'] . "'. Size must be between 1 and " . APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH;
                    return false;
                }
            }

            // Validate format if provided
            if (isset($attribute['format']) && $attribute['format'] !== '') {
                // Format is only allowed for string type
                if ($attribute['type'] !== Database::VAR_STRING) {
                    $this->message = "Format is only allowed for string type for attribute '" . $attribute['key'] . "'";
                    return false;
                }
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

            // Validate signed only for integer/bigint/float types
            if (isset($attribute['signed']) && !in_array($attribute['type'], [Database::VAR_INTEGER, Database::VAR_BIGINT, Database::VAR_FLOAT])) {
                $this->message = "Attribute '" . $attribute['key'] . "': 'signed' can only be used with integer, bigint or float types";
                return false;
            }

            // Validate required and default conflict
            if (isset($attribute['required']) && $attribute['required'] === true && isset($attribute['default'])) {
                $this->message = "Attribute '" . $attribute['key'] . "' cannot have a default value when required is true";
                return false;
            }

            // Validate array and default conflict
            if (isset($attribute['array']) && $attribute['array'] === true && isset($attribute['default'])) {
                $this->message = "Attribute '" . $attribute['key'] . "' cannot have a default value when array is true";
                return false;
            }

            // Validate min/max range for integer/bigint/float
            if (isset($attribute['min']) || isset($attribute['max'])) {
                if (!in_array($attribute['type'], [Database::VAR_INTEGER, Database::VAR_BIGINT, Database::VAR_FLOAT])) {
                    $this->message = "Attribute '" . $attribute['key'] . "': min/max can only be used with integer, bigint or float types";
                    return false;
                }

                // If both are set, validate ordering
                if (isset($attribute['min']) && isset($attribute['max']) && $attribute['min'] > $attribute['max']) {
                    $this->message = "Attribute '" . $attribute['key'] . "': minimum value must be less than or equal to maximum value";
                    return false;
                }
            }

            // Validate default value matches attribute type
            if (isset($attribute['default'])) {
                switch ($attribute['type']) {
                    case Database::VAR_STRING:
                    case Database::VAR_VARCHAR:
                    case Database::VAR_TEXT:
                    case Database::VAR_MEDIUMTEXT:
                    case Database::VAR_LONGTEXT:
                        if (!is_string($attribute['default'])) {
                            $this->message = "Default value for string attribute '" . $attribute['key'] . "' must be a string";
                            return false;
                        }

                        // Validate string size
                        $size = $attribute['size'] ?? 0;
                        if ($size > 0) {
                            $textValidator = new Text($size, 0);
                            if (!$textValidator->isValid($attribute['default'])) {
                                $this->message = "Default value for attribute '" . $attribute['key'] . "' exceeds maximum size of $size characters";
                                return false;
                            }
                        }

                        // Validate format-specific defaults
                        $format = $attribute['format'] ?? '';
                        if ($format === APP_DATABASE_ATTRIBUTE_EMAIL) {
                            $emailValidator = new Email();
                            if (!$emailValidator->isValid($attribute['default'])) {
                                $this->message = "Default value for email attribute '" . $attribute['key'] . "' must be a valid email address";
                                return false;
                            }
                        } elseif ($format === APP_DATABASE_ATTRIBUTE_IP) {
                            $ipValidator = new IP();
                            if (!$ipValidator->isValid($attribute['default'])) {
                                $this->message = "Default value for IP attribute '" . $attribute['key'] . "' must be a valid IP address";
                                return false;
                            }
                        } elseif ($format === APP_DATABASE_ATTRIBUTE_URL) {
                            $urlValidator = new URL();
                            if (!$urlValidator->isValid($attribute['default'])) {
                                $this->message = "Default value for URL attribute '" . $attribute['key'] . "' must be a valid URL";
                                return false;
                            }
                        }
                        break;

                    case Database::VAR_INTEGER:
                        if (!is_int($attribute['default'])) {
                            $this->message = "Default value for integer attribute '" . $attribute['key'] . "' must be an integer";
                            return false;
                        }
                        // Validate within range if min/max specified
                        if (isset($attribute['min']) || isset($attribute['max'])) {
                            $min = $attribute['min'] ?? \PHP_INT_MIN;
                            $max = $attribute['max'] ?? \PHP_INT_MAX;
                            $rangeValidator = new Range($min, $max, Range::TYPE_INTEGER);
                            if (!$rangeValidator->isValid($attribute['default'])) {
                                $this->message = "Default value for integer attribute '" . $attribute['key'] . "' must be between $min and $max";
                                return false;
                            }
                        }
                        break;

                    case Database::VAR_BIGINT:
                        if (!is_int($attribute['default'])) {
                            $this->message = "Default value for bigint attribute '" . $attribute['key'] . "' must be an integer";
                            return false;
                        }
                        // Validate within range if min/max specified
                        if (isset($attribute['min']) || isset($attribute['max'])) {
                            $min = $attribute['min'] ?? \PHP_INT_MIN;
                            $max = $attribute['max'] ?? \PHP_INT_MAX;
                            $rangeValidator = new Range($min, $max, Range::TYPE_INTEGER);
                            if (!$rangeValidator->isValid($attribute['default'])) {
                                $this->message = "Default value for bigint attribute '" . $attribute['key'] . "' must be between $min and $max";
                                return false;
                            }
                        }
                        break;

                    case Database::VAR_FLOAT:
                        if (!is_float($attribute['default']) && !is_int($attribute['default'])) {
                            $this->message = "Default value for float attribute '" . $attribute['key'] . "' must be a number";
                            return false;
                        }
                        // Validate within range if min/max specified
                        if (isset($attribute['min']) || isset($attribute['max'])) {
                            $min = $attribute['min'] ?? -\PHP_FLOAT_MAX;
                            $max = $attribute['max'] ?? \PHP_FLOAT_MAX;
                            $rangeValidator = new Range($min, $max, Range::TYPE_FLOAT);
                            if (!$rangeValidator->isValid((float)$attribute['default'])) {
                                $this->message = "Default value for float attribute '" . $attribute['key'] . "' must be between $min and $max";
                                return false;
                            }
                        }
                        break;

                    case Database::VAR_BOOLEAN:
                        if (!is_bool($attribute['default'])) {
                            $this->message = "Default value for boolean attribute '" . $attribute['key'] . "' must be a boolean";
                            return false;
                        }
                        break;

                    case Database::VAR_DATETIME:
                        if (!is_string($attribute['default'])) {
                            $this->message = "Default value for datetime attribute '" . $attribute['key'] . "' must be a string in ISO 8601 format";
                            return false;
                        }
                        // Basic datetime format validation
                        $datetimeValidator = new DatetimeValidator();
                        if (!$datetimeValidator->isValid($attribute['default'])) {
                            $this->message = "Default value for datetime attribute '" . $attribute['key'] . "' must be in valid ISO 8601 format";
                            return false;
                        }
                        break;
                }
            }

            // Validate enum elements if format is enum
            if (isset($attribute['format']) && $attribute['format'] === APP_DATABASE_ATTRIBUTE_ENUM) {
                if (!isset($attribute['elements']) || !is_array($attribute['elements']) || empty($attribute['elements'])) {
                    $this->message = "Attribute '" . $attribute['key'] . "' with enum format must have 'elements' array";
                    return false;
                }

                // Validate each enum element
                foreach ($attribute['elements'] as $elementIndex => $element) {
                    if (!is_string($element) || empty($element)) {
                        $this->message = "Enum element at index $elementIndex for attribute '" . $attribute['key'] . "' must be a non-empty string";
                        return false;
                    }
                    if (strlen($element) > Database::LENGTH_KEY) {
                        $this->message = "Enum element at index $elementIndex for attribute '" . $attribute['key'] . "' exceeds maximum length of " . Database::LENGTH_KEY . " characters";
                        return false;
                    }
                }

                // Validate default exists in elements
                if (isset($attribute['default'])) {
                    if (!in_array($attribute['default'], $attribute['elements'], true)) {
                        $this->message = "Default value for enum attribute '" . $attribute['key'] . "' must be one of the provided elements";
                        return false;
                    }
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
