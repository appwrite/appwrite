<?php

namespace Tests\Utopia\Agents\Roles;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Role;
use Utopia\Agents\Roles\User;

class UserTest extends TestCase
{
    public function testConstructor(): void
    {
        $id = 'test-id';
        $name = 'Test User';

        $user = new User($id, $name);

        $this->assertSame($id, $user->getId());
        $this->assertSame($name, $user->getName());
    }

    public function testConstructorWithoutName(): void
    {
        $id = 'test-id';

        $user = new User($id);

        $this->assertSame($id, $user->getId());
        $this->assertSame('', $user->getName());
    }

    public function testGetIdentifier(): void
    {
        $user = new User('test-id');

        $this->assertSame(Role::ROLE_USER, $user->getIdentifier());
    }
}
