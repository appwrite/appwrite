<?php

namespace Appwrite\Auth;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class Impersonation
{
    /**
     * Resolve an impersonation value to a user document.
     *
     * The value is disambiguated before querying:
     *   - starts with '+' → phone lookup
     *   - contains '@'   → email lookup
     *   - otherwise      → direct user ID lookup
     *
     * Returns null when the value is empty or no matching user is found.
     */
    public static function resolveUser(string $value, Database $db): ?Document
    {
        if (empty($value)) {
            return null;
        }

        $user = null;

        if (\str_starts_with($value, '+')) {
            $user = $db->getAuthorization()->skip(
                fn () => $db->findOne('users', [Query::equal('phone', [$value])])
            );
        } elseif (\str_contains($value, '@')) {
            $user = $db->getAuthorization()->skip(
                fn () => $db->findOne('users', [Query::equal('email', [\strtolower($value)])])
            );
        } else {
            $user = $db->getAuthorization()->skip(
                fn () => $db->getDocument('users', $value)
            );
        }

        return ($user === null || $user->isEmpty()) ? null : $user;
    }
}
