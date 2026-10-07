<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;

class AuthorizationTest extends TestCase
{
    protected Authorization $authorization;

    public function setUp(): void
    {
        $this->authorization = new Authorization();
    }

    public function tearDown(): void
    {
    }

    public function testValues(): void
    {
        $this->authorization->addRole(Role::any()->toString());

        $document = new Document([
            '$id' => ID::unique(),
            '$collection' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::user(ID::custom('123'))),
                Permission::read(Role::team(ID::custom('123'))),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $object = $this->authorization;

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), false);
        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, [])), false);
        $this->assertEquals($object->getDescription(), 'No permissions provided for action \'read\'');

        $this->authorization->addRole(Role::user('456')->toString());
        $this->authorization->addRole(Role::user('123')->toString());

        $this->assertEquals($this->authorization->hasRole(Role::user('456')->toString()), true);
        $this->assertEquals($this->authorization->hasRole(Role::user('457')->toString()), false);
        $this->assertEquals($this->authorization->hasRole(''), false);
        $this->assertEquals($this->authorization->hasRole(Role::any()->toString()), true);

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), true);

        $this->authorization->cleanRoles();

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), false);

        $this->authorization->addRole(Role::team('123')->toString());

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), true);

        $this->authorization->cleanRoles();
        $this->authorization->disable();

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), true);

        $this->authorization->reset();

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), false);

        $this->authorization->setDefaultStatus(false);
        $this->authorization->disable();

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), true);

        $this->authorization->reset();

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), true);

        $this->authorization->enable();

        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), false);

        $this->authorization->addRole('textX');

        $this->assertContains('textX', $this->authorization->getRoles());

        $this->authorization->removeRole('textX');

        $this->assertNotContains('textX', $this->authorization->getRoles());

        // Test skip method
        $this->assertEquals($object->isValid(new Input(Database::PERMISSION_READ, $document->getRead())), false);
        $this->assertEquals($this->authorization->skip(function () use ($object, $document) {
            return $object->isValid(new Input(Database::PERMISSION_READ, $document->getRead()));
        }), true);
    }

    public function testNestedSkips(): void
    {
        $this->assertEquals(true, $this->authorization->getStatus());

        $this->authorization->skip(function () {
            $this->assertEquals(false, $this->authorization->getStatus());

            $this->authorization->skip(function () {
                $this->assertEquals(false, $this->authorization->getStatus());

                $this->authorization->skip(function () {
                    $this->assertEquals(false, $this->authorization->getStatus());
                });

                $this->assertEquals(false, $this->authorization->getStatus());
            });

            $this->assertEquals(false, $this->authorization->getStatus());
        });

        $this->assertEquals(true, $this->authorization->getStatus());
    }
}
