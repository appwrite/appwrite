<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Roles;

class PermissionsTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /**
     * @throws DatabaseException
     */
    public function testSingleMethodSingleValue(): void
    {
        $object = new Permissions();

        $document = new Document([
            '$id' => ID::unique(),
            '$collection' => ID::unique(),
            '$permissions' => [Permission::create(Role::any())],
        ]);
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::create(Role::users())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::create(Role::user(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::create(Role::team(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::create(Role::team(ID::custom('123abc'), 'edit'))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::create(Role::guests())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::create(Role::users('verified'))];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [Permission::read(Role::any())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::read(Role::users())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::read(Role::user(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::read(Role::team(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::read(Role::team(ID::custom('123abc'), 'viewer'))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::read(Role::guests())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::read(Role::users('verified'))];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [Permission::update(Role::any())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::update(Role::users())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::update(Role::user(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::update(Role::team(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::update(Role::team(ID::custom('123abc'), 'edit'))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::update(Role::guests())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::update(Role::users('verified'))];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [Permission::delete(Role::any())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::users())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::user(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::team(ID::custom('123abc')))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::team(ID::custom('123abc'), 'edit'))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::guests())];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::users('verified'))];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [Permission::delete(Role::label('vip'))];
        $this->assertTrue($object->isValid($document->getPermissions()));
    }

    public function testMultipleMethodSingleValue(): void
    {
        $object = new Permissions();

        $document = new Document([
            '$id' => ID::unique(),
            '$collection' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [
            Permission::read(Role::users()),
            Permission::create(Role::users()),
            Permission::update(Role::users()),
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [
            Permission::read(Role::user(ID::custom('123abc'))),
            Permission::create(Role::user(ID::custom('123abc'))),
            Permission::update(Role::user(ID::custom('123abc')))
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [
            Permission::read(Role::team(ID::custom('123abc'))),
            Permission::create(Role::team(ID::custom('123abc'))),
            Permission::update(Role::team(ID::custom('123abc')))
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [
            Permission::read(Role::team(ID::custom('123abc'), 'viewer')),
            Permission::create(Role::team(ID::custom('123abc'), 'viewer')),
            Permission::update(Role::team(ID::custom('123abc'), 'viewer'))
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [
            Permission::read(Role::guests()),
            Permission::create(Role::guests()),
            Permission::update(Role::guests()),
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));

        $document['$permissions'] = [
            Permission::read(Role::users('verified')),
            Permission::create(Role::users('verified')),
            Permission::update(Role::users('verified')),
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));
    }

    public function testMultipleMethodMultipleValues(): void
    {
        $object = new Permissions();

        $document = new Document([
            '$id' => ID::unique(),
            '$collection' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::users()),
                Permission::create(Role::user(ID::custom('123abc'))),
                Permission::create(Role::team(ID::custom('123abc'))),
                Permission::update(Role::user(ID::custom('123abc'))),
                Permission::update(Role::team(ID::custom('123abc'))),
                Permission::delete(Role::user(ID::custom('123abc'))),
            ],
        ]);
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [
            Permission::read(Role::user(ID::custom('123abc'))),
            Permission::read(Role::team(ID::custom('123abc'))),
            Permission::create(Role::user(ID::custom('123abc'))),
            Permission::create(Role::team(ID::custom('123abc'))),
            Permission::update(Role::user(ID::custom('123abc'))),
            Permission::update(Role::team(ID::custom('123abc'))),
            Permission::delete(Role::user(ID::custom('123abc')))
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));
        $document['$permissions'] = [
            Permission::read(Role::any()),
            Permission::create(Role::guests()),
            Permission::update(Role::team(ID::custom('123abc'), 'edit')),
            Permission::delete(Role::team(ID::custom('123abc'), 'edit'))
        ];
        $this->assertTrue($object->isValid($document->getPermissions()));
    }

    public function testInvalidPermissions(): void
    {
        $object = new Permissions();

        $this->assertFalse($object->isValid(Permission::create(Role::any())));
        $this->assertEquals('Permissions must be an array of strings.', $object->getDescription());
        $this->assertFalse($object->isValid(false));
        $this->assertEquals('Permissions must be an array of strings.', $object->getDescription());
        $this->assertFalse($object->isValid(1.5));
        $this->assertEquals('Permissions must be an array of strings.', $object->getDescription());

        // Permissions must be of type string
        $this->assertFalse($object->isValid([0, 1.5]));
        $this->assertEquals('Every permission must be of type string.', $object->getDescription());
        $this->assertFalse($object->isValid([false, []]));
        $this->assertEquals('Every permission must be of type string.', $object->getDescription());
        $this->assertFalse($object->isValid([['a']]));
        $this->assertEquals('Every permission must be of type string.', $object->getDescription());

        // Wildcard character unsupported
        $this->assertFalse($object->isValid(['*']));
        $this->assertEquals('Wildcard permission "*" has been replaced. Use "any" instead.', $object->getDescription());

        // Role prefix values deprecated
        $this->assertFalse($object->isValid(['read("role:all")']));
        $this->assertEquals('Permissions using the "role:" prefix have been replaced. Use "users", "guests", or "any" instead.', $object->getDescription());
        $this->assertFalse($object->isValid(['create("role:guest")']));
        $this->assertEquals('Permissions using the "role:" prefix have been replaced. Use "users", "guests", or "any" instead.', $object->getDescription());
        $this->assertFalse($object->isValid(['update("role:member")']));
        $this->assertEquals('Permissions using the "role:" prefix have been replaced. Use "users", "guests", or "any" instead.', $object->getDescription());

        // Only contains a single ':'
        $this->assertFalse($object->isValid(['user1234']));
        $this->assertEquals('Permission "user1234" is not allowed. Must be one of: create, read, update, delete, write.', $object->getDescription());
        $this->assertFalse($object->isValid(['user::1234']));
        $this->assertEquals('Permission "user::1234" is not allowed. Must be one of: create, read, update, delete, write.', $object->getDescription());
        $this->assertFalse($object->isValid(['user:123:4']));
        $this->assertEquals('Permission "user:123:4" is not allowed. Must be one of: create, read, update, delete, write.', $object->getDescription());

        // Split role into format {$type}:{$value}
        // Permission must have value
        $this->assertFalse($object->isValid(['read("member:")']));
        $this->assertEquals('Role "member" must have an ID value.', $object->getDescription());
        $this->assertFalse($object->isValid(['read("user:")']));
        $this->assertEquals('Role "user" must have an ID value.', $object->getDescription());
        $this->assertFalse($object->isValid(['read("team:")']));
        $this->assertEquals('Role "team" must have an ID value.', $object->getDescription());

        // Permission role:$value must be one of: all, guest, member
        $this->assertFalse($object->isValid(['read("anyy")']));
        $this->assertEquals('Role "anyy" is not allowed. Must be one of: ' . \implode(', ', Roles::ROLES) . '.', $object->getDescription());
        $this->assertFalse($object->isValid(['read("gguest")']));
        $this->assertEquals('Role "gguest" is not allowed. Must be one of: ' . \implode(', ', Roles::ROLES) . '.', $object->getDescription());
        $this->assertFalse($object->isValid(['read("memer:123abc")']));
        $this->assertEquals('Role "memer" is not allowed. Must be one of: ' . \implode(', ', Roles::ROLES) . '.', $object->getDescription());

        // team:$value, member:$value and user:$value must have valid Key for $value
        // No leading special chars
        $this->assertFalse($object->isValid([Permission::read(Role::user('_1234'))]));
        $this->assertEquals('Role "user" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team('-1234'))]));
        $this->assertEquals('Role "team" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::member('.1234'))]));
        $this->assertEquals('Role "member" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());

        // No unsupported special characters
        $this->assertFalse($object->isValid([Permission::read(Role::user('12$4'))]));
        $this->assertEquals('Role "user" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::user('12&4'))]));
        $this->assertEquals('Role "user" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::user('ab(124'))]));
        $this->assertEquals('Role "user" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());

        // Shorter than 36 chars

        $this->assertTrue($object->isValid([Permission::read(Role::user(ID::custom(str_repeat('a', 36))))]));
        $this->assertFalse($object->isValid([Permission::read(Role::user(ID::custom(str_repeat('a', 256))))]));
        $this->assertEquals('Role "user" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());

        // Permission role must begin with one of: member, role, team, user
        $this->assertFalse($object->isValid(['update("memmber:1234")']));
        $this->assertEquals('Role "memmber" is not allowed. Must be one of: ' . \implode(', ', Roles::ROLES) . '.', $object->getDescription());
        $this->assertFalse($object->isValid(['update("tteam:1234")']));
        $this->assertEquals('Role "tteam" is not allowed. Must be one of: ' . \implode(', ', Roles::ROLES) . '.', $object->getDescription());
        $this->assertFalse($object->isValid(['update("userr:1234")']));
        $this->assertEquals('Role "userr" is not allowed. Must be one of: ' . \implode(', ', Roles::ROLES) . '.', $object->getDescription());

        // Team permission
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('_abcd')))]));
        $this->assertEquals('Role "team" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('abcd/')))]));
        $this->assertEquals('Dimension must not be empty', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom(''), 'abcd'))]));
        $this->assertEquals('Role "team" must have an ID value.', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('abcd'), '/efgh'))]));
        $this->assertEquals('Only one dimension can be provided', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('abcd'), 'e/fgh'))]));
        $this->assertEquals('Only one dimension can be provided', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('ab&cd3'), 'efgh'))]));
        $this->assertEquals('Role "team" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom(str_repeat('a', 37)), 'efgh'))]));
        $this->assertEquals('Role "team" identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('abcd'), 'ef*gh'))]));
        $this->assertEquals('Role "team" dimension value is invalid: Parameter must contain at most 81 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());
        $this->assertFalse($object->isValid([Permission::read(Role::team(ID::custom('abcd'), str_repeat('a', 82)))]));
        $this->assertEquals('Role "team" dimension value is invalid: Parameter must contain at most 81 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char', $object->getDescription());

        // Permission-list length must be valid
        $object = new Permissions(100);
        $permissions = \array_fill(0, 100, Permission::read(Role::any()));
        $this->assertTrue($object->isValid($permissions));
        $permissions[] = Permission::read(Role::any());
        $this->assertFalse($object->isValid($permissions));
        $this->assertEquals('You can only provide up to 100 permissions.', $object->getDescription());
    }

    /*
     *  Test for checking duplicate methods input. The getPermissions should return an a list array
      */
    public function testDuplicateMethods(): void
    {
        $validator = new Permissions();

        $user = ID::unique();

        $document = new Document([
            '$id' => uniqid(),
            '$collection' => uniqid(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user($user)),
                Permission::read(Role::user($user)),
                Permission::write(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ],
            'title' => 'This is a test.',
            'list' => [
                'one'
            ],
            'children' => [
                new Document(['name' => 'x']),
                new Document(['name' => 'y']),
                new Document(['name' => 'z']),
            ]
        ]);
        $this->assertTrue($validator->isValid($document->getPermissions()));
        $permissions = $document->getPermissions();
        $this->assertEquals(5, count($permissions));
        $this->assertEquals([
            'read("any")',
            'read("user:' . $user . '")',
            'write("user:' . $user . '")',
            'update("user:' . $user . '")',
            'delete("user:' . $user . '")',
        ], $permissions);
    }
}
