<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Validator\UID;
use Utopia\Validator;

class CompoundUID extends Validator
{
    public function getDescription(): string
    {
        return 'Must consist of multiple UIDs separated by a colon. Each UID must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can\'t start with a special char.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $ids = static::parse($value);

        if (\count($ids) < 2) {
            return false;
        }

        foreach ($ids as $id) {
            $validator = new UID();
            if (!$validator->isValid($id)) {
                return false;
            }
        }

        return true;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public static function parse(string $key): array
    {
        $parts = \explode(':', $key);
        $result = [];

        foreach ($parts as $part) {
            $result[] = $part;
        }

        return $result;
    }
}
