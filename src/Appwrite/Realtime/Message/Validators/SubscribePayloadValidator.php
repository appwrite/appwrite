<?php

namespace Appwrite\Realtime\Message\Validators;

use Utopia\Validator;

class SubscribePayloadValidator extends Validator
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
            if (!\is_array($payload)) {
                return 'Each subscribe payload must be an object.';
            }
            if (!\array_key_exists('channels', $payload)) {
                return 'channels is not present in payload.';
            }
            if (!\is_array($payload['channels']) || !\array_is_list($payload['channels'])) {
                return 'channels is not a valid array.';
            }
            if (\array_key_exists('queries', $payload)
                && (!\is_array($payload['queries']) || !\array_is_list($payload['queries']))
            ) {
                return 'queries is not a valid array.';
            }
        }

        return null;
    }
}
