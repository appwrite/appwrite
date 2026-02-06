<?php

namespace Appwrite\Platform;

use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Helpers\Permission as DbPermission;

trait Permission
{
    /**
     * Permissions for projects & project resources.
     *
     * @param string $teamId
     * @param string $projectId
     * @return array
     */
    public function getPermissions(string $teamId, string $projectId): array
    {
        return [
            // Team-wide permissions
            DbPermission::read(Role::team(ID::custom($teamId), 'owner')),
            DbPermission::read(Role::team(ID::custom($teamId), 'developer')),
            DbPermission::update(Role::team(ID::custom($teamId), 'owner')),
            DbPermission::update(Role::team(ID::custom($teamId), 'developer')),
            DbPermission::delete(Role::team(ID::custom($teamId), 'owner')),
            DbPermission::delete(Role::team(ID::custom($teamId), 'developer')),
            // Project-wide permissions
            DbPermission::read(Role::team(ID::custom($teamId), "project-{$projectId}-owner")),
            DbPermission::read(Role::team(ID::custom($teamId), "project-{$projectId}-developer")),
            DbPermission::update(Role::team(ID::custom($teamId), "project-{$projectId}-owner")),
            DbPermission::update(Role::team(ID::custom($teamId), "project-{$projectId}-developer")),
            DbPermission::delete(Role::team(ID::custom($teamId), "project-{$projectId}-owner")),
            DbPermission::delete(Role::team(ID::custom($teamId), "project-{$projectId}-developer")),
        ];
    }
}
