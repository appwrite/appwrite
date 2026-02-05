<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class Action extends AppwriteAction
{
    protected function getPermissions(string $teamId, string $projectId): array
    {
        return [
            Permission::read(Role::team(ID::custom($teamId))),
            Permission::update(Role::team(ID::custom($teamId), 'owner')),
            Permission::update(Role::team(ID::custom($teamId), 'developer')),
            Permission::delete(Role::team(ID::custom($teamId), 'owner')),
            Permission::delete(Role::team(ID::custom($teamId), 'developer')),
        ];
    }
}
