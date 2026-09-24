<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Glob
 *
 * Validates a string against a list of gitignore-style glob patterns.
 * Supports * (single-segment wildcard), ** (multi-segment wildcard),
 * ? (single character that does not cross /), [abc] character classes,
 * \ escape sequences, and ! prefix for exclusions.
 *
 * Matching is case-sensitive. Inclusion patterns use OR semantics;
 * exclusion patterns use AND semantics. When both are present,
 * a specific inclusion overrides a wildcard exclusion.
 *
 * Pattern syntax follows the gitignore specification.
 * See https://git-scm.com/docs/gitignore for reference.
 */
class Globstar extends Validator
{
    public function __construct(private readonly array $patterns) {}

    /**
     * Get Description
     */
    public function getDescription(): string
    {
        return 'Value must match a specific inclusion, or a wildcard inclusion not overridden by any exclusion.';
    }

    /**
     * Is valid
     *
     * Returns true if $value matches the configured glob patterns.
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($this->patterns === []) {
            return true;
        }

        $hasInclusions = false;
        foreach ($this->patterns as $p) {
            if (!str_starts_with((string) $p, '!')) {
                $hasInclusions = true;
                break;
            }
        }

        // Pure-exclusion mode: default to valid; any matching exclusion invalidates.
        if (!$hasInclusions) {
            return array_all($this->patterns, fn($pattern): bool => !$this->match($value, substr((string) $pattern, 1)));
        }

        // Inclusion mode.
        //
        // Step 1 — literal (no *, ?, [) inclusion patterns always win:
        //   if any specific inclusion matches, the value is valid regardless of later exclusions.
        $isWildcard = fn($p): bool => str_contains((string) $p, '*') || str_contains((string) $p, '?') || str_contains((string) $p, '[');

        foreach ($this->patterns as $pattern) {
            if (!str_starts_with((string) $pattern, '!') && !$isWildcard($pattern) && $this->match($value, $pattern)) {
                return true;
            }
        }

        // Step 2 — last-match-wins over the remaining (non-literal) patterns:
        //   non-! wildcard match → valid (true)
        //   ! match → invalid (false)
        //   Literal inclusions already handled above; skip them here.
        $state = false;
        foreach ($this->patterns as $pattern) {
            if (str_starts_with((string) $pattern, '!')) {
                if ($this->match($value, substr((string) $pattern, 1))) {
                    $state = false;
                }
            } elseif ($isWildcard($pattern)) {
                if ($this->match($value, $pattern)) {
                    $state = true;
                }
            }
            // literal non-! patterns are skipped (handled in step 1)
        }

        return $state;
    }

    /**
     * Is array
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    /**
     * Match a subject against a single pattern.
     */
    private function match(string $subject, string $pattern): bool
    {
        return $this->matchGlobstar($subject, $pattern);
    }

    /**
     * Match using a regex built from a pattern that contains **.
     * Handles **, *, ?, [abc] character classes, and \ escape sequences.
     */
    private function matchGlobstar(string $subject, string $pattern): bool
    {
        $regex = '';
        $len = \strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $regex .= preg_quote($pattern[$i + 1], '~');
                $i += 2;
            } elseif ($char === '[') {
                $j = $i + 1;
                $bracketContent = '';
                $isNegated = false;

                // Check for negation marker (! or ^)
                if ($j < $len && ($pattern[$j] === '!' || $pattern[$j] === '^')) {
                    $negChar = $pattern[$j];
                    $j++;
                    // If ] immediately follows the negation marker, treat ! or ^ as a
                    // literal class member (edge case: [!] or [^]) rather than as negation.
                    if ($j < $len && $pattern[$j] === ']') {
                        // Literal class containing only ! or ^ — do NOT treat as negation
                        $bracketContent .= $negChar;
                    } else {
                        $isNegated = true;
                        $bracketContent .= $negChar;
                    }
                }

                // Allow ] as first char inside bracket class (POSIX rule)
                if ($j < $len && $pattern[$j] === ']' && $bracketContent === '') {
                    $bracketContent .= ']';
                    $j++;
                } elseif ($j < $len && $pattern[$j] === ']' && $isNegated) {
                    // After a negation marker, ] as first member is literal
                    $bracketContent .= ']';
                    $j++;
                }

                while ($j < $len && $pattern[$j] !== ']') {
                    $bracketContent .= $pattern[$j];
                    $j++;
                }

                if ($j < $len) {
                    // Well-formed [...] — normalise ! negation to ^
                    $inner = $bracketContent;
                    if ($isNegated && str_starts_with($inner, '!')) {
                        $inner = '^' . substr($inner, 1);
                    } elseif ($isNegated && str_starts_with($inner, '^')) {
                        // already ^-prefixed, keep as-is
                    } elseif (!$isNegated && str_starts_with($inner, '^')) {
                        // Literal ^ as first char — escape it so PCRE doesn't treat as negation
                        $inner = '\\^' . substr($inner, 1);
                    }
                    $regex .= '[' . $inner . ']';
                    $i = $j + 1;
                } else {
                    // Unclosed bracket — treat [ as a literal character
                    $regex .= preg_quote('[', '~');
                    $i++;
                }
            } elseif ($char === '*' && isset($pattern[$i + 1]) && $pattern[$i + 1] === '*') {
                $prevSlash = $i === 0 || $pattern[$i - 1] === '/';
                $nextSlash = isset($pattern[$i + 2]) && $pattern[$i + 2] === '/';

                if ($prevSlash && $nextSlash) {
                    // a/**/b — zero or more intermediate directories
                    $regex .= '(?:.+/)?';
                    $i += 3;
                } else {
                    // foo/** or standalone ** — matches everything
                    $regex .= '.*';
                    $i += 2;
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
                $i++;
            } elseif ($char === '?') {
                $regex .= '[^/]';
                $i++;
            } else {
                $regex .= preg_quote($char, '~');
                $i++;
            }
        }

        return (bool) preg_match('~^' . $regex . '$~', $subject);
    }
}
