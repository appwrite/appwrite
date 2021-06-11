<?php

namespace Appwrite\Tests;

use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use PHPUnit\Framework\TestCase;

class AuthorizationTest extends TestCase
{
    /**
     * @var Authorization
     */
    protected $object = null;

    /**
     * @var Document
     */
    protected $document = null;

    public function setUp(): void
    {
        $this->document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => [
                'read' => ['user:123', 'team:123'],
                'write' => ['role:all'],
            ],
        ]);
        $this->object = new Authorization($this->document, 'read');
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), false);
        
        Authorization::setRole('user:456');
        Authorization::setRole('user:123');
        
        $this->assertEquals(Authorization::isRole('user:456'), true);
        $this->assertEquals(Authorization::isRole('user:457'), false);
        $this->assertEquals(Authorization::isRole(''), false);
        $this->assertEquals(Authorization::isRole('role:all'), true);

        $this->assertEquals($this->object->isValid($this->document->getPermissions()), true);
        
        Authorization::cleanRoles();
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), false);

        Authorization::setRole('team:123');
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), true);
        
        Authorization::cleanRoles();
        Authorization::disable();
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), true);

        Authorization::reset();
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), false);

        Authorization::setDefaultStatus(false);
        Authorization::disable();
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), true);

        Authorization::reset();
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), true);

        Authorization::enable();
        
        $this->assertEquals($this->object->isValid($this->document->getPermissions()), false);

        Authorization::setRole('textX');

        $this->assertContains('textX', Authorization::getRoles());

        Authorization::unsetRole('textX');

        $this->assertNotContains('textX', Authorization::getRoles());
    }
}