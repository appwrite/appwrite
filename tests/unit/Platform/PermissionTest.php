<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Appwrite\Platform\Permission;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Helpers\Permission as DbPermission;
use Utopia\Database\Helpers\Role;

final class PermissionTest extends TestCase
{
    public function testGetPermissionsIncludesTeamAndProjectSpecificRoles(): void
    {
        $permissions = $this->subject()->getPermissions('team1', 'project1');

        $this->assertSame([
            DbPermission::read(Role::team('team1', 'owner')),
            DbPermission::read(Role::team('team1', 'developer')),
            DbPermission::update(Role::team('team1', 'owner')),
            DbPermission::update(Role::team('team1', 'developer')),
            DbPermission::delete(Role::team('team1', 'owner')),
            DbPermission::delete(Role::team('team1', 'developer')),
            DbPermission::read(Role::team('team1', 'project-project1-owner')),
            DbPermission::read(Role::team('team1', 'project-project1-developer')),
            DbPermission::update(Role::team('team1', 'project-project1-owner')),
            DbPermission::update(Role::team('team1', 'project-project1-developer')),
            DbPermission::delete(Role::team('team1', 'project-project1-owner')),
            DbPermission::delete(Role::team('team1', 'project-project1-developer')),
        ], $permissions);
    }

    public function testGetPermissionsDoesNotGrantUnrelatedProjectRoles(): void
    {
        $permissions = $this->subject()->getPermissions('team1', 'project1');

        $this->assertNotContains(DbPermission::read(Role::team('team1', 'project-project2-owner')), $permissions);
        $this->assertNotContains(DbPermission::update(Role::team('team1', 'project-project2-developer')), $permissions);
        $this->assertNotContains(DbPermission::delete(Role::team('team2', 'project-project1-owner')), $permissions);
    }

    private function subject(): object
    {
        return new class () {
            use Permission;
        };
    }
}
