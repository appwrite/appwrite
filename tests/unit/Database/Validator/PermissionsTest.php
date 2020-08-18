<?php

namespace Appwrite\Tests;

use Appwrite\Database\Document;
use Appwrite\Database\Validator\Permissions;
use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase
{

    public function setUp()
    {

    }

    public function tearDown()
    {
    }

    public function testValues()
    {
        $object = new Permissions();

        $document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => [
                'read' => ['user:123', 'team:123'],
                'write' => ['*'],
            ],
        ]);
        
        $this->assertEquals($object->isValid($document->getPermissions()), true);
        
        $document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => [
                'read' => ['user:123', 'team:123'],
            ],
        ]);
        
        $this->assertEquals($object->isValid($document->getPermissions()), true);
        
        $document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => [
                'read' => ['user:123', 'team:123'],
                'write' => ['*'],
                'unknown' => ['*'],
            ],
        ]);
        
        $this->assertEquals($object->isValid($document->getPermissions()), false);
        
        $document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => 'test',
        ]);
        
        $this->assertEquals($object->isValid($document->getPermissions()), false);

        $document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => [
                'read' => 'unknown',
                'write' => ['*'],
            ],
        ]);
        
        $this->assertEquals($object->isValid($document->getPermissions()), false);
        
    }
}