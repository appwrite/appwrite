<?php

namespace Appwrite\Auth;

use Utopia\Database\Database;
use Utopia\Database\Document;

class Impersonation
{
    /**
     * Resolve an impersonation user ID to a user document.
     * Returns null when the value is empty or no matching user is found.
     */
    public static function resolveUser(string $userId, Database $db): ?Document
    {
        if (empty($userId)) {
            return null;
        }

        $user = $db->getAuthorization()->skip(
            fn () => $db->getDocument('users', $userId)
        );

        return $user->isEmpty() ? null : $user;
    }
}
