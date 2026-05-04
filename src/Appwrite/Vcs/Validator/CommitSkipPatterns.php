<?php

namespace Appwrite\Vcs\Validator;

use Utopia\Validator;

class CommitSkipPatterns extends Validator
{
    public function __construct(private readonly array $patterns)
    {
    }

    /**
     * Returns false (skip deployment) when the commit message contains any of the
     * configured patterns (case-insensitive substring match).
     * Returns true (proceed) when no patterns are configured or none match.
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (stripos($value, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Commit message must not contain any of the configured skip patterns.';
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
