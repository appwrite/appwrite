<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Validator;

class Folder extends Validator
{
    public const MAX_LENGTH = 2048;

    public function getDescription(): string
    {
        return 'Folder must be `/`-separated segments without empty, `.`, or `..` segments, must not start with `/`, must not contain control characters, and must be at most ' . self::MAX_LENGTH . ' characters long including the trailing slash.';
    }

    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($value === '') {
            return true;
        }

        if (\strlen(self::normalize($value)) > self::MAX_LENGTH) {
            return false;
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        if (\str_ends_with($value, '/')) {
            $value = \substr($value, 0, -1);
        }

        if ($value === '') {
            return false;
        }

        foreach (\explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical stored form: trailing slash when non-empty.
     */
    public static function normalize(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return \str_ends_with($value, '/') ? $value : $value . '/';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
