<?php

namespace Appwrite\Platform\Modules\Payments\Validator;

use Utopia\Validator;

class PricingEntry extends Validator
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
     * Validate a pricing entry
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        // Must be array-like
        if (!\is_array($value)) {
            $this->description = 'Pricing entry must be an array';
            return false;
        }

        // Validate priceId - required, non-empty string
        if (!\array_key_exists('priceId', $value)) {
            $this->description = 'Missing required key: priceId';
            return false;
        }

        if (!\is_string($value['priceId']) || \trim($value['priceId']) === '') {
            $this->description = "Key 'priceId' must be a non-empty string";
            return false;
        }

        // Validate amount - should be present (integer)
        if (\array_key_exists('amount', $value)) {
            if (!\is_int($value['amount']) && !\is_float($value['amount'])) {
                $this->description = "Key 'amount' must be a numeric value";
                return false;
            }
        }

        // Validate currency - should be present (string)
        if (\array_key_exists('currency', $value)) {
            if (!\is_string($value['currency'])) {
                $this->description = "Key 'currency' must be a string";
                return false;
            }
        }

        // Validate interval - should be present (string)
        if (\array_key_exists('interval', $value)) {
            if (!\is_string($value['interval'])) {
                $this->description = "Key 'interval' must be a string";
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
