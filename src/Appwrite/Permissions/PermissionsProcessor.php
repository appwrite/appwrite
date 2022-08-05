<?php

namespace Appwrite\Permissions;

class PermissionsProcessor
{
    public static function processAggregatePermissions(array $permissions): array
    {
        $aggregates = [
            'admin' => ['create', 'update', 'delete', 'read',],
            'write' => ['create', 'update', 'delete',],
        ];
        foreach($permissions as $i => $permission) {
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
    }
}