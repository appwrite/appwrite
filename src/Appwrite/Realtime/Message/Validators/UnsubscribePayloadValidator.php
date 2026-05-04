<?php

namespace Appwrite\Realtime\Message\Validators;

use Utopia\Validator;

class UnsubscribePayloadValidator extends Validator
{
    public function getDescription(): string
    {
        return 'Payload is not valid.';
    }

    public function isArray(): bool
    {
        return true;
    }

    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }

    public function isValid(mixed $value): bool
    {
        return $this->getValidationError($value) === null;
    }

    public function getValidationError(mixed $value): ?string
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            return 'Payload is not valid.';
        }

        foreach ($value as $payload) {
            if (
                !\is_array($payload)
                || !\array_key_exists('subscriptionId', $payload)
                || !\is_string($payload['subscriptionId'])
                || $payload['subscriptionId'] === ''
            ) {
                return 'Each unsubscribe payload must include a non-empty subscriptionId.';
            }
        }

        return null;
    }
}
