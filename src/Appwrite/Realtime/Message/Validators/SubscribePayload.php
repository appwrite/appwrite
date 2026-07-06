<?php

namespace Appwrite\Realtime\Message\Validators;

use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\Validator;

class SubscribePayload extends Validator
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

        $customId = new CustomId();

        foreach ($value as $payload) {
            if (!\is_array($payload)) {
                $this->description = 'Each subscribe payload must be an object.';
                return false;
            }
            if (\array_key_exists('subscriptionId', $payload) && !$customId->isValid($payload['subscriptionId'])) {
                $this->description = 'subscriptionId is not a valid id.';
                return false;
            }
            if (!\array_key_exists('channels', $payload)) {
                $this->description = 'channels is not present in payload.';
                return false;
            }
            if (!\is_array($payload['channels']) || !\array_is_list($payload['channels'])) {
                $this->description = 'channels is not a valid array.';
                return false;
            }
            foreach ($payload['channels'] as $channel) {
                if (!\is_string($channel)) {
                    $this->description = 'channels must contain only strings.';
                    return false;
                }
            }
            if (\array_key_exists('queries', $payload)
                && (!\is_array($payload['queries']) || !\array_is_list($payload['queries']))
            ) {
                $this->description = 'queries is not a valid array.';
                return false;
            }
        }

        return true;
    }
}
