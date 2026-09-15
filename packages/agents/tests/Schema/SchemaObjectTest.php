<?php

namespace Tests\Utopia\Agents;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Schema\SchemaObject;

class SchemaObjectTest extends TestCase
{
    public function testConstructorAndGetProperties(): void
    {
        $properties = [
            'id' => ['type' => SchemaObject::TYPE_STRING],
            'age' => ['type' => SchemaObject::TYPE_INTEGER],
        ];
        $object = new SchemaObject($properties);
        $this->assertSame($properties, $object->getProperties());
    }

    public function testGetProperty(): void
    {
        $object = new SchemaObject([
            'id' => ['type' => SchemaObject::TYPE_STRING],
        ]);
        $this->assertSame(['type' => SchemaObject::TYPE_STRING], $object->getProperty('id'));
        $this->assertNull($object->getProperty('nonexistent'));
    }

    public function testAddPropertyAndRemoveProperty(): void
    {
        $object = new SchemaObject();
        $object->addProperty('id', ['type' => SchemaObject::TYPE_STRING]);
        $this->assertSame(['id' => ['type' => SchemaObject::TYPE_STRING]], $object->getProperties());
        $object->removeProperty('id');
        $this->assertSame([], $object->getProperties());
    }

    public function testAddPropertyInvalidType(): void
    {
        $object = new SchemaObject();
        $this->expectException(\InvalidArgumentException::class);
        $object->addProperty('bad', ['type' => 'invalid_type']);
    }

    public function testGetNames(): void
    {
        $object = new SchemaObject([
            'id' => ['type' => SchemaObject::TYPE_STRING],
            'age' => ['type' => SchemaObject::TYPE_INTEGER],
        ]);
        $this->assertSame(['id', 'age'], $object->getNames());
    }

    public function testGetValidTypes(): void
    {
        $expected = [
            SchemaObject::TYPE_STRING,
            SchemaObject::TYPE_ARRAY,
            SchemaObject::TYPE_BOOLEAN,
            SchemaObject::TYPE_INTEGER,
            SchemaObject::TYPE_NUMBER,
            SchemaObject::TYPE_OBJECT,
            SchemaObject::TYPE_NULL,
        ];
        $this->assertSame($expected, SchemaObject::getValidTypes());
    }
}
