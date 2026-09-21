<?php

namespace Appwrite\Advisor\Validator;

use Utopia\Validator;

class CTAs extends Validator
{
    public const MAX_COUNT_DEFAULT = 16;

    protected string $message = 'Value must be an array of CTA descriptors. Each entry must define `label`, `service`, `method`, and an optional `params` object.';
    protected array $allowedServices;
    protected array $allowedMethods;

    public function __construct(
        protected int $maxCount = self::MAX_COUNT_DEFAULT,
        ?array $allowedServices = null,
        ?array $allowedMethods = null,
    ) {
        $this->allowedServices = $allowedServices ?? ADVISOR_CTA_SERVICES;
        $this->allowedMethods = $allowedMethods ?? ADVISOR_CTA_METHODS;
    }

    public function getDescription(): string
    {
        return $this->message;
    }

    public function isArray(): bool
    {
        return true;
    }

    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }

    public function isValid($value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        if (\count($value) > $this->maxCount) {
            $this->message = "A maximum of {$this->maxCount} CTAs are allowed per insight.";
            return false;
        }

        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                return false;
            }

            $maxLengths = ['label' => 256, 'service' => 64, 'method' => 64];
            foreach ($maxLengths as $required => $maxLength) {
                if (!isset($entry[$required]) || !\is_string($entry[$required]) || $entry[$required] === '') {
                    return false;
                }
                if (\strlen($entry[$required]) > $maxLength) {
                    $this->message = "CTA `{$required}` must not exceed {$maxLength} characters.";
                    return false;
                }
            }

            if (!empty($this->allowedServices) && !\in_array($entry['service'], $this->allowedServices, true)) {
                $this->message = "CTA `service` must be one of: " . \implode(', ', $this->allowedServices) . '.';
                return false;
            }

            if (!empty($this->allowedMethods) && !\in_array($entry['method'], $this->allowedMethods, true)) {
                $this->message = "CTA `method` must be one of: " . \implode(', ', $this->allowedMethods) . '.';
                return false;
            }

            if (isset($entry['params']) && !\is_array($entry['params']) && !\is_object($entry['params'])) {
                return false;
            }
        }

        return true;
    }
}
