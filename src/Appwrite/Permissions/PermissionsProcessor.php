<?php

namespace Appwrite\Permissions;

use Utopia\Database\Database;
use Utopia\Database\Permission;

class PermissionsProcessor
{
    public static function aggregate(?array $permissions, string $resource): ?array
    {
        if (\is_null($permissions)) {
            return null;
        }

        $aggregates = self::getAggregates($resource);

        foreach ($permissions as $i => $permission) {
            $permission = Permission::parse($permission);
            foreach ($aggregates as $type => $subTypes) {
                if ($permission->getPermission() != $type) {
                    continue;
                }
                foreach ($subTypes as $subType) {
                    $permissions[] = (new Permission(
                        $subType,
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                }
                unset($permissions[$i]);
            }
        }
        return $permissions;
    }

    private static function getAggregates($resource): array
    {
        $aggregates = [];

        switch ($resource) {
            case 'document':
            case 'file':
                $aggregates['write'] = [
                    Database::PERMISSION_UPDATE,
                    Database::PERMISSION_DELETE
                ];
                break;
            case 'collection':
            case 'bucket':
                $aggregates['write'] = [
                    Database::PERMISSION_CREATE,
                    Database::PERMISSION_UPDATE,
                    Database::PERMISSION_DELETE
                ];
                break;
        }

        return $aggregates;
    }
}
