<?php

namespace Appwrite\Realtime\Message\Validators;

use Utopia\Validator;

class SubscribePayloadValidator extends Validator
{
    private string $message = 'Payload is not valid.';

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

    public function isValid(mixed $value): bool
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            $this->message = 'Payload is not valid.';
            return false;
        }

        foreach ($value as $payload) {
            if (!\is_array($payload)) {
                $this->message = 'Each subscribe payload must be an object.';
                return false;
            }
            if (!\array_key_exists('channels', $payload)) {
                $this->message = 'channels is not present in payload.';
                return false;
            }
            if (!\is_array($payload['channels']) || !\array_is_list($payload['channels'])) {
                $this->message = 'channels is not a valid array.';
                return false;
            }
            if (\array_key_exists('queries', $payload)
                && (!\is_array($payload['queries']) || !\array_is_list($payload['queries']))
            ) {
                $this->message = 'queries is not a valid array.';
                return false;
            }
        }

        return true;
    }
}
