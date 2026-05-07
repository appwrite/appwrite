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
     * configured patterns as a standalone directive (case-insensitive).
     * Returns true (proceed) when no patterns are configured or none match.
     *
     * Matching rules:
     * - Case-insensitive
     * - The directive must be surrounded by whitespace or string boundaries, so
     *   "prefix[skip deploy]suffix" does NOT accidentally skip
     * - Internal whitespace in the pattern is normalised: tokens are split on \s+
     *   and rejoined with \s* in the regex, so "[skip   deploy]" matches
     *   "[skip deploy]" and "skip-checks: true" matches "skip-checks:true"
     */
    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            // Split on whitespace; each token is regex-quoted. Tokens are rejoined
            // with \s+ (required space) so that "skipappwrite" does NOT match the
            // pattern "skip appwrite". The only exception: when the preceding token
            // ends with ":" (git trailer style), \s* is used so that
            // "skip-checks:true" still matches the pattern "skip-checks: true".
            $tokens = preg_split('/\s+/', $pattern);
            $regexParts = [];
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $regexParts[] = preg_quote($tokens[$i], '~');
                if ($i < $count - 1) {
                    $regexParts[] = str_ends_with($tokens[$i], ':') ? '\s*' : '\s+';
                }
            }
            $regexBody = implode('', $regexParts);

            // (?<!\S) / (?!\S) assert whitespace (or string edge) on both sides,
            // ensuring the directive is a standalone group, not buried inside a
            // longer token like "prefix[skip deploy]suffix".
            $regex = '~(?<!\S)' . $regexBody . '(?!\S)~i';

            if (preg_match($regex, $value)) {
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
