<?php

namespace Appwrite\Realtime\Message\Validators;

use Utopia\Validator;

class UnsubscribePayload extends Validator
{
    protected string $description = 'Payload is not valid.';

    public function getDescription(): string
    {
        return $this->description;
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
            $this->description = 'Payload is not valid.';
            return false;
        }

        foreach ($value as $payload) {
            if (
                !\is_array($payload)
                || !\array_key_exists('subscriptionId', $payload)
                || !\is_string($payload['subscriptionId'])
                || $payload['subscriptionId'] === ''
            ) {
                $this->description = 'Each unsubscribe payload must include a non-empty subscriptionId.';
                return false;
            }
        }

        return true;
    }
}
