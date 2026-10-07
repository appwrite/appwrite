<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class RoleTest extends TestCase
{
    public function testOutputFromString(): void
    {
        $role = Role::parse('any');
        $this->assertEquals('any', $role->getRole());
        $this->assertEmpty($role->getIdentifier());
        $this->assertEmpty($role->getDimension());

        $role = Role::parse('guests');
        $this->assertEquals('guests', $role->getRole());
        $this->assertEmpty($role->getIdentifier());
        $this->assertEmpty($role->getDimension());

        $role = Role::parse('users');
        $this->assertEquals('users', $role->getRole());
        $this->assertEmpty($role->getIdentifier());
        $this->assertEmpty($role->getDimension());

        $role = Role::parse('user:123');
        $this->assertEquals('user', $role->getRole());
        $this->assertEquals('123', $role->getIdentifier());
        $this->assertEmpty($role->getDimension());

        $role = Role::parse('team:123');
        $this->assertEquals('team', $role->getRole());
        $this->assertEquals('123', $role->getIdentifier());
        $this->assertEmpty($role->getDimension());

        $role = Role::parse('team:123/456');
        $this->assertEquals('team', $role->getRole());
        $this->assertEquals('123', $role->getIdentifier());
        $this->assertEquals('456', $role->getDimension());

        $role = Role::parse('team:123/project-456-owner');
        $this->assertEquals('team', $role->getRole());
        $this->assertEquals('123', $role->getIdentifier());
        $this->assertEquals('project-456-owner', $role->getDimension());

        $role = Role::parse('team:123/project-456');
        $this->assertEquals('team', $role->getRole());
        $this->assertEquals('123', $role->getIdentifier());
        $this->assertEquals('project-456', $role->getDimension());

        $role = Role::parse('user:123/verified');
        $this->assertEquals('user', $role->getRole());
        $this->assertEquals('123', $role->getIdentifier());
        $this->assertEquals('verified', $role->getDimension());

        $role = Role::parse('users/verified');
        $this->assertEquals('users', $role->getRole());
        $this->assertEmpty($role->getIdentifier());
        $this->assertEquals('verified', $role->getDimension());

        $role = Role::parse('label:vip');
        $this->assertEquals('label', $role->getRole());
        $this->assertEquals('vip', $role->getIdentifier());
        $this->assertEmpty($role->getDimension());
    }

    public function testInputFromParameters(): void
    {
        $role = new Role('any');
        $this->assertEquals('any', $role->toString());

        $role = new Role('guests');
        $this->assertEquals('guests', $role->toString());

        $role = new Role('users');
        $this->assertEquals('users', $role->toString());

        $role = new Role('user', '123');
        $this->assertEquals('user:123', $role->toString());

        $role = new Role('team', '123');
        $this->assertEquals('team:123', $role->toString());

        $role = new Role('team', '123', '456');
        $this->assertEquals('team:123/456', $role->toString());

        $role = new Role('team', '123', 'project-456-owner');
        $this->assertEquals('team:123/project-456-owner', $role->toString());

        $role = new Role('team', '123', 'project-456');
        $this->assertEquals('team:123/project-456', $role->toString());

        $role = new Role('label', 'vip');
        $this->assertEquals('label:vip', $role->toString());
    }

    public function testInputFromRoles(): void
    {
        $role = Role::any();
        $this->assertEquals('any', $role->toString());

        $role = Role::guests();
        $this->assertEquals('guests', $role->toString());

        $role = Role::users();
        $this->assertEquals('users', $role->toString());

        $role = Role::user(ID::custom('123'));
        $this->assertEquals('user:123', $role->toString());

        $role = Role::team(ID::custom('123'));
        $this->assertEquals('team:123', $role->toString());

        $role = Role::team(ID::custom('123'), '456');
        $this->assertEquals('team:123/456', $role->toString());

        $role = Role::team(ID::custom('123'), 'project-456-owner');
        $this->assertEquals('team:123/project-456-owner', $role->toString());

        $role = Role::team(ID::custom('123'), 'project-456');
        $this->assertEquals('team:123/project-456', $role->toString());

        $role = Role::label('vip');
        $this->assertEquals('label:vip', $role->toString());
    }

    public function testInputFromID(): void
    {
        $role = Role::user(ID::custom('123'));
        $this->assertEquals('user:123', $role->toString());

        $role = Role::team(ID::custom('123'));
        $this->assertEquals('team:123', $role->toString());

        $role = Role::team(ID::custom('123'), '456');
        $this->assertEquals('team:123/456', $role->toString());
    }
}
