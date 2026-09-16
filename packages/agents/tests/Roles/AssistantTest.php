<?php

namespace Tests\Utopia\Agents\Roles;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Role;
use Utopia\Agents\Roles\Assistant;

class AssistantTest extends TestCase
{
    public function testConstructor(): void
    {
        $id = 'test-id';
        $name = 'Test Assistant';

        $assistant = new Assistant($id, $name);

        $this->assertSame($id, $assistant->getId());
        $this->assertSame($name, $assistant->getName());
    }

    public function testConstructorWithoutName(): void
    {
        $id = 'test-id';

        $assistant = new Assistant($id);

        $this->assertSame($id, $assistant->getId());
        $this->assertSame('', $assistant->getName());
    }

    public function testGetIdentifier(): void
    {
        $assistant = new Assistant('test-id');

        $this->assertSame(Role::ROLE_ASSISTANT, $assistant->getIdentifier());
    }
}
