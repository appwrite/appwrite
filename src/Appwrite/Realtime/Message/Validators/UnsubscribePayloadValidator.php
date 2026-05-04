<?php

namespace Appwrite\Realtime\Message\Validators;

use Utopia\Validator;

class UnsubscribePayloadValidator extends Validator
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
            if (
                !\is_array($payload)
                || !\array_key_exists('subscriptionId', $payload)
                || !\is_string($payload['subscriptionId'])
                || $payload['subscriptionId'] === ''
            ) {
                $this->message = 'Each unsubscribe payload must include a non-empty subscriptionId.';
                return false;
            }
        }

        return true;
    }
}
