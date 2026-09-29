<?php

namespace Appwrite\Utopia\Database\Validator;

use Utopia\Database\Database;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Permissions;

/**
 * Permissions as a route can judge them.
 *
 * A permission may be scoped to a single column, and whether that column exists is a
 * question about a collection. A route validator has no collection: it runs before the
 * action, receives only its own value and injected resources, and the database and table
 * ids it would need are path params the framework never hands it. So it answers what a
 * permission string can answer on its own and leaves existence to the write, which holds
 * the collection and rejects an unresolvable column there.
 *
 * Each permission still reaches the parent, with its column removed, so everything the
 * parent checks is still checked -- allowed actions, role and dimension parsing, the
 * length cap. Only the column half is handled here, and only for shape.
 */
class RoutePermissions extends Permissions
{
    protected string $message = 'Permissions Error';

    /**
     * Is valid.
     *
     * @param mixed $permissions
     * @return bool
     */
    public function isValid($permissions): bool
    {
        // A validator answers yes or no. Anything thrown while working that out is still
        // an answer about the input, so it is caught here and reported as invalid --
        // letting it escape would leave the route with an uncaught exception and give
        // the caller a server error for what is their own malformed input.
        try {
            return $this->validate($permissions);
        } catch (\Throwable $e) {
            $this->message = 'Permissions must be an array of valid permission strings.';

            return false;
        }
    }

    /**
     * @param mixed $permissions
     * @return bool
     */
    private function validate($permissions): bool
    {
        if (!\is_array($permissions)) {
            return parent::isValid($permissions);
        }

        $unscoped = [];

        foreach ($permissions as $permission) {
            if (!\is_string($permission)) {
                return parent::isValid([$permission]);
            }

            try {
                $parsed = Permission::parse($permission);
            } catch (\Throwable) {
                // Malformed: the parent owns the wording for every shape of that.
                return parent::isValid([$permission]);
            }

            if ($parsed->isForAllColumns()) {
                $unscoped[] = $permission;
                continue;
            }

            // These two rules are the library's, repeated here because the parent only
            // reaches them for a permission that still carries its column -- and the
            // whole point below is to hand it one that does not. If the library changes
            // them, change them here too.
            if (\in_array($parsed->getPermission(), [Database::PERMISSION_DELETE, Database::PERMISSION_WRITE], true)) {
                $this->message = 'Permission "' . $parsed->getPermission() . '" cannot be scoped to a column, it applies to the whole row.';
                return false;
            }

            if (!$this->key->isValid($parsed->getColumn())) {
                $this->message = 'Column "' . $parsed->getColumn() . '" is not a valid column key.';
                return false;
            }

            // Everything else about this permission is the parent's business, and it can
            // only judge it without the column -- which is exactly the half it is
            // qualified to judge here.
            $unscoped[] = (new Permission(
                $parsed->getPermission(),
                $parsed->getRole(),
                $parsed->getIdentifier(),
                $parsed->getDimension()
            ))->toString();
        }

        return parent::isValid($unscoped);
    }
}
