<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DocumentTest extends TestCase
{
    /**
     * @var Document
     */
    protected ?Document $document = null;

    /**
     * @var Document
     */
    protected ?Document $empty = null;

    /**
     * @var string
     */
    protected ?string $id = null;

    /**
     * @var string
     */
    protected ?string $collection = null;

    public function setUp(): void
    {
        $this->id = uniqid();

        $this->collection = uniqid();

        $this->document = new Document([
            '$id' => ID::custom($this->id),
            '$collection' => ID::custom($this->collection),
            '$permissions' => [
                Permission::read(Role::user(ID::custom('123'))),
                Permission::read(Role::team(ID::custom('123'))),
                Permission::create(Role::any()),
                Permission::create(Role::user(ID::custom('creator'))),
                Permission::update(Role::any()),
                Permission::update(Role::user(ID::custom('updater'))),
                Permission::delete(Role::any()),
                Permission::delete(Role::user(ID::custom('deleter'))),
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

        $this->empty = new Document();
    }

    public function tearDown(): void
    {
    }

    public function testDocumentNulls(): void
    {
        $data = [
            'cat' => null,
            'dog' => null, // last entry is null
        ];

        $document = new Document($data);

        $this->assertEquals(null, $document['cat']);
        $this->assertEquals(false, isset($document['cat']));
        $this->assertEquals('cat', $document->getAttribute('cat', 'cat'));

        $this->assertEquals(null, $document['dog']);
        $this->assertEquals(false, isset($document['dog']));
        $this->assertEquals('dog', $document->getAttribute('dog', 'dog'));
    }

    public function testId(): void
    {
        $this->assertEquals($this->id, $this->document->getId());
        $this->assertEquals(null, $this->empty->getId());
    }

    public function testCollection(): void
    {
        $this->assertEquals($this->collection, $this->document->getCollection());
        $this->assertEquals(null, $this->empty->getCollection());
    }

    public function testGetCreate(): void
    {
        $this->assertEquals(['any', 'user:creator'], $this->document->getCreate());
        $this->assertEquals([], $this->empty->getCreate());
    }

    public function testGetRead(): void
    {
        $this->assertEquals(['user:123', 'team:123'], $this->document->getRead());
        $this->assertEquals([], $this->empty->getRead());
    }

    public function testGetUpdate(): void
    {
        $this->assertEquals(['any', 'user:updater'], $this->document->getUpdate());
        $this->assertEquals([], $this->empty->getUpdate());
    }

    public function testGetDelete(): void
    {
        $this->assertEquals(['any', 'user:deleter'], $this->document->getDelete());
        $this->assertEquals([], $this->empty->getDelete());
    }

    public function testGetPermissionByType(): void
    {
        $this->assertEquals(['any','user:creator'], $this->document->getPermissionsByType(Database::PERMISSION_CREATE));
        $this->assertEquals([], $this->empty->getPermissionsByType(Database::PERMISSION_CREATE));

        $this->assertEquals(['user:123','team:123'], $this->document->getPermissionsByType(Database::PERMISSION_READ));
        $this->assertEquals([], $this->empty->getPermissionsByType(Database::PERMISSION_READ));

        $this->assertEquals(['any','user:updater'], $this->document->getPermissionsByType(Database::PERMISSION_UPDATE));
        $this->assertEquals([], $this->empty->getPermissionsByType(Database::PERMISSION_UPDATE));

        $this->assertEquals(['any','user:deleter'], $this->document->getPermissionsByType(Database::PERMISSION_DELETE));
        $this->assertEquals([], $this->empty->getPermissionsByType(Database::PERMISSION_DELETE));
    }

    public function testGetPermissions(): void
    {
        $this->assertEquals([
            Permission::read(Role::user(ID::custom('123'))),
            Permission::read(Role::team(ID::custom('123'))),
            Permission::create(Role::any()),
            Permission::create(Role::user(ID::custom('creator'))),
            Permission::update(Role::any()),
            Permission::update(Role::user(ID::custom('updater'))),
            Permission::delete(Role::any()),
            Permission::delete(Role::user(ID::custom('deleter'))),
        ], $this->document->getPermissions());
    }

    public function testGetAttributes(): void
    {
        $this->assertEquals([
            'title' => 'This is a test.',
            'list' => [
                'one'
            ],
            'children' => [
                new Document(['name' => 'x']),
                new Document(['name' => 'y']),
                new Document(['name' => 'z']),
            ]
        ], $this->document->getAttributes());
    }

    public function testGetAttribute(): void
    {
        $this->assertEquals('This is a test.', $this->document->getAttribute('title', ''));
        $this->assertEquals('', $this->document->getAttribute('titlex', ''));
    }

    public function testSetAttribute(): void
    {
        $this->assertEquals('This is a test.', $this->document->getAttribute('title', ''));
        $this->assertEquals(['one'], $this->document->getAttribute('list', []));
        $this->assertEquals('', $this->document->getAttribute('titlex', ''));

        $this->document->setAttribute('title', 'New title');

        $this->assertEquals('New title', $this->document->getAttribute('title', ''));
        $this->assertEquals('', $this->document->getAttribute('titlex', ''));

        $this->document->setAttribute('list', 'two', Document::SET_TYPE_APPEND);
        $this->assertEquals(['one', 'two'], $this->document->getAttribute('list', []));

        $this->document->setAttribute('list', 'zero', Document::SET_TYPE_PREPEND);
        $this->assertEquals(['zero', 'one', 'two'], $this->document->getAttribute('list', []));

        $this->document->setAttribute('list', ['one'], Document::SET_TYPE_ASSIGN);
        $this->assertEquals(['one'], $this->document->getAttribute('list', []));
    }

    public function testSetAttributes(): void
    {
        $document = new Document(['$id' => ID::custom(''), '$collection' => 'users']);

        $otherDocument = new Document([
            '$id' => ID::custom('new'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user('new')),
                Permission::delete(Role::user('new')),
            ],
            'email' => 'joe@example.com',
            'prefs' => new \stdClass(),
        ]);

        $document->setAttributes($otherDocument->getArrayCopy());

        $this->assertEquals($otherDocument->getId(), $document->getId());
        $this->assertEquals('users', $document->getCollection());
        $this->assertEquals($otherDocument->getPermissions(), $document->getPermissions());
        $this->assertEquals($otherDocument->getAttribute('email'), $document->getAttribute('email'));
        $this->assertEquals($otherDocument->getAttribute('prefs'), $document->getAttribute('prefs'));
    }

    public function testRemoveAttribute(): void
    {
        $this->document->removeAttribute('list');
        $this->assertEquals([], $this->document->getAttribute('list', []));
    }

    public function testFind(): void
    {
        $this->assertEquals(null, $this->document->find('find', 'one'));

        $this->document->setAttribute('findString', 'demo');
        $this->assertEquals($this->document, $this->document->find('findString', 'demo'));

        $this->document->setAttribute('findArray', ['demo']);
        $this->assertEquals(null, $this->document->find('findArray', 'demo'));
        $this->assertEquals($this->document, $this->document->find('findArray', ['demo']));

        $this->assertEquals($this->document->getAttribute('children')[0], $this->document->find('name', 'x', 'children'));
        $this->assertEquals($this->document->getAttribute('children')[2], $this->document->find('name', 'z', 'children'));
        $this->assertEquals(null, $this->document->find('name', 'v', 'children'));
    }

    public function testFindAndReplace(): void
    {
        $document = new Document([
            '$id' => ID::custom($this->id),
            '$collection' => ID::custom($this->collection),
            '$permissions' => [
                Permission::read(Role::user(ID::custom('123'))),
                Permission::read(Role::team(ID::custom('123'))),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
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

        $this->assertEquals(true, $document->findAndReplace('name', 'x', new Document(['name' => '1', 'test' => true]), 'children'));
        $this->assertEquals('1', $document->getAttribute('children')[0]['name']);
        $this->assertEquals(true, $document->getAttribute('children')[0]['test']);

        // Array with wrong value
        $this->assertEquals(false, $document->findAndReplace('name', 'xy', new Document(['name' => '1', 'test' => true]), 'children'));

        // Array with wrong key
        $this->assertEquals(false, $document->findAndReplace('namex', 'x', new Document(['name' => '1', 'test' => true]), 'children'));

        // No array
        $this->assertEquals(true, $document->findAndReplace('title', 'This is a test.', 'new'));
        $this->assertEquals('new', $document->getAttribute('title'));

        // No array with wrong value
        $this->assertEquals(false, $document->findAndReplace('title', 'test', 'new'));

        // No array with wrong key
        $this->assertEquals(false, $document->findAndReplace('titlex', 'This is a test.', 'new'));
    }

    public function testFindAndRemove(): void
    {
        $document = new Document([
            '$id' => ID::custom($this->id),
            '$collection' => ID::custom($this->collection),
            '$permissions' => [
                Permission::read(Role::user(ID::custom('123'))),
                Permission::read(Role::team(ID::custom('123'))),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
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
        $this->assertEquals(true, $document->findAndRemove('name', 'x', 'children'));
        $this->assertEquals('y', $document->getAttribute('children')[1]['name']);
        $this->assertCount(2, $document->getAttribute('children'));

        // Array with wrong value
        $this->assertEquals(false, $document->findAndRemove('name', 'xy', 'children'));

        // Array with wrong key
        $this->assertEquals(false, $document->findAndRemove('namex', 'x', 'children'));

        // No array
        $this->assertEquals(true, $document->findAndRemove('title', 'This is a test.'));
        $this->assertEquals(false, $document->isset('title'));

        // No array with wrong value
        $this->assertEquals(false, $document->findAndRemove('title', 'new'));

        // No array with wrong key
        $this->assertEquals(false, $document->findAndRemove('titlex', 'This is a test.'));
    }

    public function testIsEmpty(): void
    {
        $this->assertEquals(false, $this->document->isEmpty());
        $this->assertEquals(true, $this->empty->isEmpty());
    }

    public function testIsSet(): void
    {
        $this->assertEquals(false, $this->document->isSet('titlex'));
        $this->assertEquals(false, $this->empty->isSet('titlex'));
        $this->assertEquals(true, $this->document->isSet('title'));
    }

    public function testClone(): void
    {
        $before = new Document([
            'level' => 0,
            'name' => '_',
            'document' => new Document(['name' => 'zero']),
            'children' => [
                new Document([
                    'level' => 1,
                    'name' => 'a',
                    'document' => new Document(['name' => 'one']),
                    'children' => [
                        new Document([
                            'level' => 2,
                            'name' => 'x',
                            'document' => new Document(['name' => 'two']),
                            'children' => [
                                new Document([
                                    'level' => 3,
                                    'name' => 'i'
                                ]),
                            ]
                        ])
                    ]
                ])
            ]
        ]);

        $after = clone $before;

        $before->setAttribute('name', 'before');
        $before->getAttribute('document')->setAttribute('name', 'before_one');
        $before->getAttribute('children')[0]->setAttribute('name', 'before_a');
        $before->getAttribute('children')[0]->getAttribute('document')->setAttribute('name', 'before_two');
        $before->getAttribute('children')[0]->getAttribute('children')[0]->setAttribute('name', 'before_x');

        $this->assertEquals('_', $after->getAttribute('name'));
        $this->assertEquals('zero', $after->getAttribute('document')->getAttribute('name'));
        $this->assertEquals('a', $after->getAttribute('children')[0]->getAttribute('name'));
        $this->assertEquals('one', $after->getAttribute('children')[0]->getAttribute('document')->getAttribute('name'));
        $this->assertEquals('x', $after->getAttribute('children')[0]->getAttribute('children')[0]->getAttribute('name'));
    }

    public function testGetArrayCopy(): void
    {
        $this->assertEquals([
            '$id' => ID::custom($this->id),
            '$collection' => ID::custom($this->collection),
            '$permissions' => [
                Permission::read(Role::user(ID::custom('123'))),
                Permission::read(Role::team(ID::custom('123'))),
                Permission::create(Role::any()),
                Permission::create(Role::user(ID::custom('creator'))),
                Permission::update(Role::any()),
                Permission::update(Role::user(ID::custom('updater'))),
                Permission::delete(Role::any()),
                Permission::delete(Role::user(ID::custom('deleter'))),
            ],
            'title' => 'This is a test.',
            'list' => [
                'one'
            ],
            'children' => [
                ['name' => 'x'],
                ['name' => 'y'],
                ['name' => 'z'],
            ]
        ], $this->document->getArrayCopy());
        $this->assertEquals([], $this->empty->getArrayCopy());
    }

    public function testEmptyDocumentSequence(): void
    {
        $empty = new Document();

        $this->assertNull($empty->getSequence());
        $this->assertNotSame('', $empty->getSequence());
    }
    public function testConstructionPreservesScalarArraysAndConvertsOnlyDocuments(): void
    {
        $object = new \stdClass();
        $input = [
            'empty' => [],
            'values' => [7 => 'text', 'null' => null, 'bool' => false, 'object' => $object],
            'child' => ['$id' => 'child', 'name' => 'nested'],
            'children' => ['first' => ['$id' => 'first'], 9 => 'plain'],
        ];
        $document = new Document($input);

        $this->assertSame([], $document->getAttribute('empty'));
        $this->assertSame($input['values'], $document->getAttribute('values'));
        $this->assertSame('child', $document->getAttribute('child')->getId());
        $this->assertSame('first', $document->getAttribute('children')['first']->getId());
        $this->assertSame('plain', $document->getAttribute('children')[9]);
        $this->assertSame(['$id' => 'first'], $input['children']['first']);
    }

    public function testArrayCopyPreservesKeysAndFiltersNestedDocuments(): void
    {
        $document = new Document([
            'name' => 'parent',
            'secret' => 'hidden',
            'values' => [7 => 'seven', 'null' => null, 'empty' => []],
            'children' => ['child' => new Document(['name' => 'nested', 'secret' => 'hidden'])],
        ]);
        $copy = $document->getArrayCopy(['name', 'secret', 'values', 'children'], ['secret']);

        $this->assertSame([
            'name' => 'parent',
            'values' => [7 => 'seven', 'null' => null, 'empty' => []],
            'children' => ['child' => ['name' => 'nested']],
        ], $copy);
        $copy['values'][7] = 'changed';
        $copy['children']['child']['name'] = 'changed';
        $this->assertSame('seven', $document->getAttribute('values')[7]);
        $this->assertSame('nested', $document->getAttribute('children')['child']->getAttribute('name'));
    }

    public function testClonePreservesScalarKeysAndIsolatesNestedDocuments(): void
    {
        $object = new \stdClass();
        $original = new Document([
            'empty' => [],
            'values' => [7 => 'seven', 'object' => $object],
            'children' => ['child' => new Document(['name' => 'nested']), 9 => 'plain'],
        ]);
        $copy = clone $original;
        $copy['values'][7] = 'changed';
        $copy->getAttribute('children')['child']->setAttribute('name', 'changed');

        $this->assertSame([], $copy->getAttribute('empty'));
        $this->assertSame([7, 'object'], array_keys($copy->getAttribute('values')));
        $this->assertSame($object, $copy->getAttribute('values')['object']);
        $this->assertSame('seven', $original->getAttribute('values')[7]);
        $this->assertSame('nested', $original->getAttribute('children')['child']->getAttribute('name'));
        $this->assertSame('plain', $copy->getAttribute('children')[9]);
    }

    public function testArrayCopyAndCloneDetachReferencedArrayElements(): void
    {
        $scalar = 'before';
        $nested = ['value' => 'before'];
        $document = new Document(['values' => ['first' => &$scalar, 7 => &$nested, 'last' => false]]);
        $export = $document->getArrayCopy();
        $clone = clone $document;
        $scalar = 'after';
        $nested['value'] = 'after';

        $expected = ['first' => 'before', 7 => ['value' => 'before'], 'last' => false];
        $this->assertSame($expected, $export['values']);
        $this->assertSame($expected, $clone->getAttribute('values'));
        $this->assertSame('after', $document->getAttribute('values')['first']);
    }

    public function testScalarArrayExportAvoidsReferenceAllocationOverhead(): void
    {
        $document = new Document(['values' => range(1, 100_000)]);
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $copy = $document->getArrayCopy();
        $allocated = memory_get_peak_usage() - $before;

        $this->assertCount(100_000, $copy['values']);
        $this->assertLessThan(3 * 1024 * 1024, $allocated, 'Export should copy the array without wrapping every element in a reference');
        $copy['values'][0] = 0;
        $this->assertSame(1, $document->getAttribute('values')[0]);
    }

}
