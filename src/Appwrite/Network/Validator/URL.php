<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\URL as UtopiaURL;

/**
 * URL
 *
 * Extends the base URL validator to support internationalized URLs
 * containing non-ASCII characters in path, query or fragment components.
 * Non-ASCII characters are percent-encoded before validation so that URLs
 * like "https://example.com/path/šum" are accepted.
 */
class URL extends UtopiaURL
{
    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Value must be a valid URL';
    }

    /**
     * Is valid
     *
     * Validation will pass if $value is a valid URL, including those with
     * non-ASCII (Unicode) characters in path, query, or fragment segments.
     *
     * @param mixed $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if (parent::isValid($value)) {
            return true;
        }

        // Attempt to encode non-ASCII characters in the URL components and
        // retry validation.  This handles internationalized URLs such as
        // "https://example.com/café" or "https://example.com/šum".
        if (!\is_string($value) || empty($value)) {
            return false;
        }

        $parts = \parse_url($value);
        if ($parts === false) {
            return false;
        }

        if (!isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        // Re-build the URL with non-ASCII path segments percent-encoded so
        // that filter_var(FILTER_VALIDATE_URL) will accept them.
        $encoded = $parts['scheme'] . '://';

        if (isset($parts['user'])) {
            $encoded .= $parts['user'];
            if (isset($parts['pass'])) {
                $encoded .= ':' . $parts['pass'];
            }
            $encoded .= '@';
        }

        $encoded .= $parts['host'];

        if (isset($parts['port'])) {
            $encoded .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            // Encode each path segment individually, preserving '/' separators.
            // rawurldecode first to avoid double-encoding already-encoded chars.
            $encoded .= \implode('/', \array_map(
                fn (string $segment) => \rawurlencode(\rawurldecode($segment)),
                \explode('/', $parts['path'])
            ));
        }

        if (isset($parts['query'])) {
            $encoded .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $encoded .= '#' . $parts['fragment'];
        }

        return parent::isValid($encoded);
    }
}
