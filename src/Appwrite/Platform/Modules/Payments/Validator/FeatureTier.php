<?php

namespace Appwrite\Platform\Modules\Payments\Validator;

use Utopia\Validator;

class FeatureTier extends Validator
{
    private string $description = '';

    /**
     * Get description of the validation error
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Indicates this validator expects an array
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return true;
    }

    /**
     * Validate a feature tier entry for metered billing
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        // Must be array-like
        if (!\is_array($value)) {
            $this->description = 'Feature tier must be an array';
            return false;
        }

        // Validate upTo - should be present (integer or 'inf')
        if (\array_key_exists('upTo', $value)) {
            if (!\is_int($value['upTo']) && $value['upTo'] !== 'inf' && $value['upTo'] !== null) {
                $this->description = "Key 'upTo' must be an integer, 'inf', or null";
                return false;
            }
        }

        // Validate unitAmount - should be present (numeric)
        if (\array_key_exists('unitAmount', $value)) {
            if (!\is_int($value['unitAmount']) && !\is_float($value['unitAmount'])) {
                $this->description = "Key 'unitAmount' must be a numeric value";
                return false;
            }
        }

        // Validate flatAmount - optional (numeric)
        if (\array_key_exists('flatAmount', $value)) {
            if (!\is_int($value['flatAmount']) && !\is_float($value['flatAmount']) && $value['flatAmount'] !== null) {
                $this->description = "Key 'flatAmount' must be a numeric value or null";
                return false;
            }
        }

        // Validate firstUnit - optional (integer)
        if (\array_key_exists('firstUnit', $value)) {
            if (!\is_int($value['firstUnit'])) {
                $this->description = "Key 'firstUnit' must be an integer";
                return false;
            }
        }

        // Validate lastUnit - optional (integer or null for infinity)
        if (\array_key_exists('lastUnit', $value)) {
            if (!\is_int($value['lastUnit']) && $value['lastUnit'] !== null) {
                $this->description = "Key 'lastUnit' must be an integer or null";
                return false;
            }
        }

        return true;
    }

    /**
     * Get the type of this validator
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
