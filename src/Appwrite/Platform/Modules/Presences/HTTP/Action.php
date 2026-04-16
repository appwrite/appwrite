<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;

class Action extends PlatformAction
{
    public function setPermission(Document $document, ?array $permissions, User $user, Authorization $authorization): Document
    {
        $isAPIKey = $user->isApp($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
            Database::PERMISSION_WRITE,
        ];

        $permissions = Permission::aggregate($permissions, $allowedPermissions);

        // Add permissions for current the user if none were provided.
        if (\is_null($permissions)) {
            $permissions = [];
            if (!empty($user->getId()) && !$isPrivilegedUser) {
                foreach ($allowedPermissions as $permission) {
                    $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                }
            }
        }

        // Users can only manage their own roles, API keys and Admin users can manage any
        if (!$isAPIKey && !$isPrivilegedUser) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!$authorization->hasRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $authorization->getRoles()) . ')');
                    }
                }
            }
        }

        sort($permissions, SORT_STRING);
        $document->setAttribute('$permissions', $permissions);

        return $document;
    }
}
