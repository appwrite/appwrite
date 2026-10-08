<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;

/**
 * Users can only grant roles they hold on a related document written through its parent.
 * Permissions the related document already has may be sent back unchanged.
 */
final readonly class RelatedPermissions
{
    public function __construct(
        private Authorization $authorization,
    ) {
    }

    /**
     * @throws Exception
     */
    public function validate(mixed $permissions, Document $current): void
    {
        if ($permissions === null) {
            return;
        }

        $validator = new Permissions();
        if (!$validator->isValid($permissions)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
        }

        $granted = \array_diff(Permission::aggregate($permissions) ?? [], $current->getPermissions());

        foreach ($granted as $permission) {
            $permission = Permission::parse($permission);
            $role = (new Role(
                $permission->getRole(),
                $permission->getIdentifier(),
                $permission->getDimension()
            ))->toString();

            if (!$this->authorization->hasRole($role)) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $this->authorization->getRoles()) . ')');
            }
        }
    }
}
