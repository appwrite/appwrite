<?php

namespace Appwrite\Permissions;

use Appwrite\Auth\Auth;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;

class PermissionsProcessor
{
    public static function handleAggregates(?array $permissions): ?array
    {
        if (\is_null($permissions)) {
            return null;
        }
        $aggregates = [
            'admin' => Database::PERMISSIONS,
        ];
        foreach ($permissions as $i => $permission) {
            foreach ($aggregates as $type => $subTypes) {
                if (!\str_starts_with($permission, $type)) {
                    continue;
                }
                $permissionsContents = \str_replace([$type . '(', ')', ' '], '', $permission);
                foreach ($subTypes as $subType) {
                    $permissions[] = $subType . '(' . $permissionsContents . ')';
                }
                unset($permissions[$i]);
            }
        }
        return $permissions;
    }

    public static function addDefaultsIfNeeded(
        ?array $permissions,
        string $userId,
        array $allowedPermissions = Database::PERMISSIONS
    ): array {
        if (\is_null($permissions)) {
            $permissions = [];
            if (!empty($userId)) {
                foreach ($allowedPermissions as $permission) {
                    $permissions[] = $permission . '(user:' . $userId . ')';
                }
            }
            return $permissions;
        }
        foreach ($allowedPermissions as $permission) {
            // Default any missing allowed permissions to the current user
            if (empty(\preg_grep("#^{$permission}\(.+\)$#", $permissions)) && !empty($userId)) {
                $permissions[] = $permission . '(user:' . $userId . ')';
            }
        }
        return $permissions;
    }

    public static function allowedForUserType(array $permissions): bool
    {
        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = Authorization::getRoles();

        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    if (!\str_starts_with($permission, $type)) {
                        continue;
                    }
                    $role = \str_replace([$type, '(', ')', ' '], '', $permission);
                    if (!Authorization::isRole($role)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public static function allowedForResourceType(string $resourceType, array $permissions): bool
    {
        return match ($resourceType) {
            'document',
            'file' => empty(\preg_grep("#^create\(.+\)$#", $permissions)),
            default => true
        };
    }
}
