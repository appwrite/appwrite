<?php

namespace Appwrite\Vcs\Validator;

use Utopia\Validator;

class BuildTrigger extends Validator
{
    public function __construct(private readonly array $patterns) {}

    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        if (empty($this->patterns)) {
            return true;
        }

        $include = array_filter($this->patterns, fn ($p) => !str_starts_with($p, '!'));
        $exclude = array_filter($this->patterns, fn ($p) => str_starts_with($p, '!'));

        if (empty($include)) {
            // Only exclusions: pass everything unless excluded.
            foreach ($exclude as $pattern) {
                if ($this->matchGlob($value, substr($pattern, 1))) {
                    return false;
                }
            }
            return true;
        }

        // A pattern is "specific" when it contains no wildcard characters.
        $isSpecific = fn($p) => !str_contains($p, '*') && !str_contains($p, '?');

        // 1. Specific inclusion always wins — an explicit exact match is never blocked.
        foreach ($include as $pattern) {
            if ($isSpecific($pattern) && $this->matchGlob($value, $pattern)) {
                return true;
            }
        }

        // 2. Specific exclusion overrides a wildcard inclusion — refines broad patterns.
        foreach ($exclude as $pattern) {
            $raw = substr($pattern, 1);
            if ($isSpecific($raw) && $this->matchGlob($value, $raw)) {
                return false;
            }
        }

        // 3. Wildcard inclusion wins over any remaining wildcard exclusion.
        foreach ($include as $pattern) {
            if (!$isSpecific($pattern) && $this->matchGlob($value, $pattern)) {
                return true;
            }
        }

        // No inclusion matched.
        return false;
    }

    public function getDescription(): string
    {
        return 'Value must match a specific inclusion, or a wildcard inclusion not overridden by a specific exclusion.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    private function matchGlob(string $subject, string $pattern): bool
    {
        $regex = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $char = $pattern[$i];

            if ($char === '*' && isset($pattern[$i + 1]) && $pattern[$i + 1] === '*') {
                $prevSlash = $i === 0 || $pattern[$i - 1] === '/';
                $nextSlash = isset($pattern[$i + 2]) && $pattern[$i + 2] === '/';

                if ($prevSlash && $nextSlash) {
                    // a/**/b → zero or more intermediate dirs (matches a/b, a/x/b, a/x/y/b)
                    // **/foo  → zero or more leading dirs (matches foo, a/foo, a/b/foo)
                    $regex .= '(?:.+/)?';
                    $i += 3; // consume ** and the trailing /
                } else {
                    // foo/** → everything inside (matches foo/a, foo/a/b)
                    $regex .= '.*';
                    $i += 2;
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*'; // anything except a path separator
                $i++;
            } elseif ($char === '?') {
                $regex .= '[^/]'; // any single character except a path separator
                $i++;
            } else {
                $regex .= preg_quote($char, '~');
                $i++;
            }
        }

        return (bool) preg_match('~^' . $regex . '$~', $subject);
    }
}
