<?php

namespace Appwrite\Platform\Modules\Payments\Validator;

use Utopia\Validator;

class ProviderConfig extends Validator
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
     * Validate a provider configuration object
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        // Must be array-like
        if (!\is_array($value)) {
            $this->description = 'Provider configuration must be an array';
            return false;
        }

        // Must have 'providers' key
        if (!\array_key_exists('providers', $value)) {
            $this->description = "Missing required key: providers";
            return false;
        }

        if (!\is_array($value['providers'])) {
            $this->description = "Key 'providers' must be an array";
            return false;
        }

        // Validate each provider entry
        foreach ($value['providers'] as $providerId => $providerConfig) {
            if (!\is_string($providerId) || \trim($providerId) === '') {
                $this->description = 'Provider ID must be a non-empty string';
                return false;
            }

            if (!\is_array($providerConfig)) {
                $this->description = "Provider '{$providerId}' configuration must be an array";
                return false;
            }

            // Validate secretKey - required, non-empty string
            if (!\array_key_exists('secretKey', $providerConfig)) {
                $this->description = "Missing required key 'secretKey' for provider '{$providerId}'";
                return false;
            }

            if (!\is_string($providerConfig['secretKey']) || \trim($providerConfig['secretKey']) === '') {
                $this->description = "Key 'secretKey' for provider '{$providerId}' must be a non-empty string";
                return false;
            }

            // Validate optional 'enabled' key if present
            if (\array_key_exists('enabled', $providerConfig)) {
                if (!\is_bool($providerConfig['enabled'])) {
                    $this->description = "Key 'enabled' for provider '{$providerId}' must be a boolean";
                    return false;
                }
            }

            // Validate optional 'defaults' key if present
            if (\array_key_exists('defaults', $providerConfig)) {
                if (!\is_array($providerConfig['defaults'])) {
                    $this->description = "Key 'defaults' for provider '{$providerId}' must be an array";
                    return false;
                }
            }
        }

        // Validate optional top-level 'enabled' key if present
        if (\array_key_exists('enabled', $value)) {
            if (!\is_bool($value['enabled'])) {
                $this->description = "Key 'enabled' must be a boolean";
                return false;
            }
        }

        // Validate optional top-level 'defaults' key if present
        if (\array_key_exists('defaults', $value)) {
            if (!\is_array($value['defaults'])) {
                $this->description = "Key 'defaults' must be an array";
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
