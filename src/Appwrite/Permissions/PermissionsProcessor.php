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
            'admin' => ['create', 'update', 'delete', 'read',],
            'write' => ['create', 'update', 'delete',],
        ];
        foreach ($permissions as $i => $permission) {
            foreach ($aggregates as $type => $subTypes) {
                if (!\str_starts_with($permission, $type)) {
                    continue;
                }
                $permissionsContents = \str_replace([$type, '(', ')', ' '], '', $permission);
                foreach ($subTypes as $subType) {
                    $permissions[] = $subType . '(' . $permissionsContents . ')';
                }
                unset($permissions[$i]);
            }
        }
        return $permissions;
    }

    public static function addDefaultsIfNeeded(?array $permissions, string $userId): array
    {
        if (\is_null($permissions)) {
            $permissions = [];
            if (!empty($userId)) {
                $permissions = [
                    'read(user:' . $userId . ') ',
                    'create(user:' . $userId . ') ',
                    'update(user:' . $userId . ') ',
                    'delete(user:' . $userId . ') ',
                ];
            }
            return $permissions;
        }
        foreach (Database::PERMISSIONS as $permission) {
            if (empty(\preg_grep("#^{$permission}\(.+\)$#", $permissions)) && !empty($userId)) {
                $permissions[] = $permission . '(user:' . $userId . ')';
            }
        }
        return $permissions;
    }
}
