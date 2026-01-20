<?php

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Registry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase
{
    // ============================================
    // Constructor and Project ID
    // ============================================

    public function testConstructorWithProjectId(): void
    {
        $registry = new Registry('myProject');
        $this->assertEquals('myProject', $registry->getProjectId());
    }

    public function testConstructorWithoutProjectId(): void
    {
        $registry = new Registry();
        $this->assertEquals('', $registry->getProjectId());
    }

    public function testSetProjectId(): void
    {
        $registry = new Registry('project1');
        $registry->setProjectId('project2');
        $this->assertEquals('project2', $registry->getProjectId());
    }

    // ============================================
    // Base Types
    // ============================================

    public function testInitBaseTypes(): void
    {
        $registry = new Registry('project1');

        $registry->initBaseTypes([
            'string' => Type::string(),
            'boolean' => Type::boolean(),
        ]);

        $this->assertTrue($registry->has('string'));
        $this->assertTrue($registry->has('boolean'));
        $this->assertSame(Type::string(), $registry->get('string'));
    }

    public function testBaseTypesNotAffectedByClear(): void
    {
        $registry = new Registry('project1');

        $registry->initBaseTypes(['baseType' => Type::string()]);
        $registry->set('customType', Type::int());

        $registry->clear();

        $this->assertTrue($registry->has('baseType'));
        $this->assertFalse($registry->has('customType'));
    }

    public function testClearWithIncludeBaseTypes(): void
    {
        $registry = new Registry('project1');

        $registry->initBaseTypes(['baseType' => Type::string()]);
        $registry->set('customType', Type::int());

        $registry->clear(true);

        $this->assertFalse($registry->has('baseType'));
        $this->assertFalse($registry->has('customType'));
    }

    public function testInitBaseTypesMultipleTimes(): void
    {
        $registry = new Registry('project1');

        $registry->initBaseTypes(['type1' => Type::string()]);
        $registry->initBaseTypes(['type2' => Type::int()]);

        $this->assertTrue($registry->has('type1'));
        $this->assertTrue($registry->has('type2'));
    }

    public function testBaseTypesOverwrite(): void
    {
        $registry = new Registry('project1');

        $originalType = Type::string();
        $newType = Type::int();

        $registry->initBaseTypes(['shared' => $originalType]);
        $registry->initBaseTypes(['shared' => $newType]);

        $this->assertSame($newType, $registry->get('shared'));
    }

    // ============================================
    // Instance Isolation
    // ============================================

    public function testInstanceIsolation(): void
    {
        $registry1 = new Registry('project1');
        $registry2 = new Registry('project2');

        $type1 = Type::string();
        $type2 = Type::int();

        $registry1->set('customType', $type1);
        $registry2->set('customType', $type2);

        $this->assertSame($type1, $registry1->get('customType'));
        $this->assertSame($type2, $registry2->get('customType'));
    }

    public function testInstanceTypesNotVisibleToOtherInstances(): void
    {
        $registry1 = new Registry('project1');
        $registry2 = new Registry('project2');

        $registry1->set('project1OnlyType', Type::string());

        $this->assertFalse($registry2->has('project1OnlyType'));
    }

    public function testMultipleTypesPerInstance(): void
    {
        $registry = new Registry('project1');

        $registry->set('type1', Type::string());
        $registry->set('type2', Type::int());
        $registry->set('type3', Type::boolean());

        $this->assertTrue($registry->has('type1'));
        $this->assertTrue($registry->has('type2'));
        $this->assertTrue($registry->has('type3'));
    }

    // ============================================
    // Clear Operations
    // ============================================

    public function testClear(): void
    {
        $registry = new Registry('project1');

        $registry->set('type1', Type::string());
        $registry->set('type2', Type::int());

        $registry->clear();

        $this->assertFalse($registry->has('type1'));
        $this->assertFalse($registry->has('type2'));
    }

    public function testClearMultipleTimes(): void
    {
        $registry = new Registry('project1');

        $registry->set('type1', Type::string());

        $registry->clear();
        $registry->clear();
        $registry->clear();

        $this->assertFalse($registry->has('type1'));
    }

    // ============================================
    // Get/Set Operations
    // ============================================

    public function testSetAndGet(): void
    {
        $registry = new Registry('project1');
        $type = Type::string();

        $registry->set('myType', $type);

        $this->assertSame($type, $registry->get('myType'));
    }

    public function testSetOverwrites(): void
    {
        $registry = new Registry('project1');
        $type1 = Type::string();
        $type2 = Type::int();

        $registry->set('myType', $type1);
        $registry->set('myType', $type2);

        $this->assertSame($type2, $registry->get('myType'));
    }

    public function testHas(): void
    {
        $registry = new Registry('project1');

        $this->assertFalse($registry->has('nonexistent'));

        $registry->set('exists', Type::string());
        $this->assertTrue($registry->has('exists'));
    }

    public function testGetThrowsForNonExistentType(): void
    {
        $registry = new Registry('project1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Type 'nonexistent' not found in registry for project 'project1'");

        $registry->get('nonexistent');
    }

    // ============================================
    // Complex Types
    // ============================================

    public function testObjectType(): void
    {
        $registry = new Registry('project1');

        $objectType = new ObjectType([
            'name' => 'CustomObject',
            'fields' => [
                'id' => ['type' => Type::id()],
                'name' => ['type' => Type::string()],
            ]
        ]);

        $registry->set('CustomObject', $objectType);

        $this->assertSame($objectType, $registry->get('CustomObject'));
    }

    public function testListType(): void
    {
        $registry = new Registry('project1');
        $listType = Type::listOf(Type::string());

        $registry->set('StringList', $listType);

        $this->assertSame($listType, $registry->get('StringList'));
    }

    public function testNonNullType(): void
    {
        $registry = new Registry('project1');
        $nonNullType = Type::nonNull(Type::string());

        $registry->set('NonNullString', $nonNullType);

        $this->assertSame($nonNullType, $registry->get('NonNullString'));
    }

    // ============================================
    // Edge Cases - Type Names
    // ============================================

    public function testEmptyTypeName(): void
    {
        $registry = new Registry('project1');
        $type = Type::string();

        $registry->set('', $type);

        $this->assertTrue($registry->has(''));
        $this->assertSame($type, $registry->get(''));
    }

    public function testTypeNameWithSpecialCharacters(): void
    {
        $registry = new Registry('project1');
        $type = Type::string();

        $specialNames = [
            'Type-With-Dashes',
            'Type_With_Underscores',
            'Type.With.Dots',
            'Type:With:Colons',
            'Type With Spaces',
        ];

        foreach ($specialNames as $name) {
            $registry->set($name, $type);
            $this->assertTrue($registry->has($name), "Failed for name: {$name}");
            $this->assertSame($type, $registry->get($name), "Failed to get: {$name}");
        }
    }

    public function testVeryLongTypeName(): void
    {
        $registry = new Registry('project1');
        $type = Type::string();
        $longName = str_repeat('a', 10000);

        $registry->set($longName, $type);

        $this->assertTrue($registry->has($longName));
        $this->assertSame($type, $registry->get($longName));
    }

    // ============================================
    // isBaseType Parameter
    // ============================================

    public function testSetWithIsBaseTypeTrue(): void
    {
        $registry = new Registry('project1');
        $type = Type::string();

        $registry->set('sharedType', $type, true);

        // Base types should be in getBaseTypes()
        $baseTypes = $registry->getBaseTypes();
        $this->assertArrayHasKey('sharedType', $baseTypes);
        $this->assertSame($type, $baseTypes['sharedType']);
    }

    public function testSetWithIsBaseTypeFalse(): void
    {
        $registry = new Registry('project1');
        $type = Type::string();

        $registry->set('projectType', $type, false);

        // Project types should be in getTypes()
        $types = $registry->getTypes();
        $this->assertArrayHasKey('projectType', $types);
        $this->assertSame($type, $types['projectType']);

        // Should not be in base types
        $baseTypes = $registry->getBaseTypes();
        $this->assertArrayNotHasKey('projectType', $baseTypes);
    }

    // ============================================
    // getTypes and getBaseTypes
    // ============================================

    public function testGetTypes(): void
    {
        $registry = new Registry('project1');

        $type1 = Type::string();
        $type2 = Type::int();

        $registry->set('type1', $type1);
        $registry->set('type2', $type2);

        $types = $registry->getTypes();

        $this->assertCount(2, $types);
        $this->assertSame($type1, $types['type1']);
        $this->assertSame($type2, $types['type2']);
    }

    public function testGetBaseTypes(): void
    {
        $registry = new Registry('project1');

        $registry->initBaseTypes([
            'string' => Type::string(),
            'int' => Type::int(),
        ]);

        $baseTypes = $registry->getBaseTypes();

        $this->assertCount(2, $baseTypes);
        $this->assertSame(Type::string(), $baseTypes['string']);
        $this->assertSame(Type::int(), $baseTypes['int']);
    }

    // ============================================
    // Stress Tests
    // ============================================

    public function testManyTypesInOneRegistry(): void
    {
        $registry = new Registry('project1');

        for ($i = 0; $i < 1000; $i++) {
            $registry->set("type{$i}", Type::string());
        }

        for ($i = 0; $i < 1000; $i++) {
            $this->assertTrue($registry->has("type{$i}"), "Failed for type{$i}");
        }
    }

    public function testManyRegistryInstances(): void
    {
        $registries = [];

        for ($i = 0; $i < 100; $i++) {
            $registries[$i] = new Registry("project{$i}");
            $registries[$i]->set('projectSpecificType', Type::string());
        }

        // Verify each registry has its type
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($registries[$i]->has('projectSpecificType'));
        }
    }

    // ============================================
    // Regression Tests
    // ============================================

    public function testBaseTypeTakesPrecedenceOverProjectType(): void
    {
        $registry = new Registry('project1');

        $baseType = Type::string();
        $projectType = Type::int();

        $registry->initBaseTypes(['sharedName' => $baseType]);
        // This should go to project types, not overwrite base type
        $registry->set('sharedName', $projectType, false);

        // Base type should still be returned (checked first)
        $this->assertSame($baseType, $registry->get('sharedName'));
    }

    public function testSetAfterClear(): void
    {
        $registry = new Registry('project1');

        $registry->set('type1', Type::string());
        $registry->clear();

        // Should work normally after clear
        $registry->set('type2', Type::int());

        $this->assertTrue($registry->has('type2'));
        $this->assertFalse($registry->has('type1'));
    }
}
