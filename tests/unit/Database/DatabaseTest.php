<?php

namespace Appwrite\Tests;

use PDO;
use Exception;
use Appwrite\Database\Adapter\Relational;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    /**
     * @var bool
     */
    static protected $init = false;

    /**
     * @var Database
     */
    static protected $object = null;

    /**
     * @var Document
     */
    static protected $collection = '';

    public function setUp(): void
    {
        Authorization::disable();

        if(self::$init === true) {
            return;
        }

        $dbHost = getenv('_APP_DB_HOST');
        $dbUser = getenv('_APP_DB_USER');
        $dbPass = getenv('_APP_DB_PASS');
        $dbScheme = getenv('_APP_DB_SCHEMA');
        $namespace = 'test_'.uniqid();

        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            PDO::ATTR_TIMEOUT => 3, // Seconds
            PDO::ATTR_PERSISTENT => true
        ));

        // Connection settings
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // Return arrays
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

        self::$object = new Database();
        self::$object->setAdapter(new Relational($pdo));

        self::$object->setMocks([
            Database::COLLECTION_COLLECTIONS => [
                '$collection' => Database::COLLECTION_COLLECTIONS,
                '$id' => Database::COLLECTION_COLLECTIONS,
                '$permissions' => ['read' => ['*']],
                'name' => 'Collections',
                'rules' => [
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Name',
                        'key' => 'name',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Date Created',
                        'key' => 'dateCreated',
                        'type' => Database::VAR_NUMERIC,
                        'default' => 0,
                        'required' => false,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Date Updated',
                        'key' => 'dateUpdated',
                        'type' => Database::VAR_NUMERIC,
                        'default' => 0,
                        'required' => false,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Rules',
                        'key' => 'rules',
                        'type' => Database::VAR_DOCUMENT,
                        'default' => [],
                        'required' => false,
                        'array' => true,
                        'list' => [Database::COLLECTION_RULES],
                    ],
                ],
            ],
            Database::COLLECTION_RULES => [
                '$collection' => Database::COLLECTION_COLLECTIONS,
                '$id' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'name' => 'Collections Rule',
                'rules' => [
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Label',
                        'key' => 'label',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Key',
                        'key' => 'key',
                        'type' => Database::VAR_KEY,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Type',
                        'key' => 'type',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Default',
                        'key' => 'default',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => false,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Required',
                        'key' => 'required',
                        'type' => Database::VAR_BOOLEAN,
                        'default' => true,
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Array',
                        'key' => 'array',
                        'type' => Database::VAR_BOOLEAN,
                        'default' => true,
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'list',
                        'key' => 'list',
                        'type' => Database::VAR_TEXT,
                        //'default' => '',
                        'required' => false,
                        'array' => true,
                    ],
                ],
            ],
            Database::COLLECTION_USERS => [
                '$collection' => Database::COLLECTION_COLLECTIONS,
                '$id' => Database::COLLECTION_USERS,
                '$permissions' => ['read' => ['*']],
                'name' => 'User',
                'rules' => [
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Name',
                        'key' => 'name',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => false,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Email',
                        'key' => 'email',
                        'type' => Database::VAR_EMAIL,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Status',
                        'key' => 'status',
                        'type' => Database::VAR_NUMERIC,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Password',
                        'key' => 'password',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Password Update Date',
                        'key' => 'password-update',
                        'type' => Database::VAR_NUMERIC,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Prefs',
                        'key' => 'prefs',
                        'type' => Database::VAR_TEXT,
                        'default' => '',
                        'required' => false,
                        'array' => false,
                        'filter' => ['json']
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Registration Date',
                        'key' => 'registration',
                        'type' => Database::VAR_NUMERIC,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Email Verification Status',
                        'key' => 'emailVerification',
                        'type' => Database::VAR_BOOLEAN,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Reset',
                        'key' => 'reset',
                        'type' => Database::VAR_BOOLEAN,
                        'default' => '',
                        'required' => true,
                        'array' => false,
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Tokens',
                        'key' => 'tokens',
                        'type' => Database::VAR_DOCUMENT,
                        'default' => [],
                        'required' => false,
                        'array' => true,
                        'list' => [Database::COLLECTION_TOKENS],
                    ],
                    [
                        '$collection' => Database::COLLECTION_RULES,
                        'label' => 'Memberships',
                        'key' => 'memberships',
                        'type' => Database::VAR_DOCUMENT,
                        'default' => [],
                        'required' => false,
                        'array' => true,
                        'list' => [Database::COLLECTION_MEMBERSHIPS],
                    ],
                ],
            ]
        ]);

        self::$object->setNamespace($namespace);
        self::$object->createNamespace($namespace);

        self::$collection = self::$object->createDocument(Database::COLLECTION_COLLECTIONS, [
            '$collection' => Database::COLLECTION_COLLECTIONS,
            '$permissions' => ['read' => ['*']],
            'name' => 'Tasks',
            'rules' => [
                [
                    '$collection' => Database::COLLECTION_RULES,
                    '$permissions' => ['read' => ['*']],
                    'label' => 'Task Name',
                    'key' => 'name',
                    'type' => Database::VAR_TEXT,
                    'default' => '',
                    'required' => true,
                    'array' => false,
                ],
            ],
        ]);

        self::$init = true;
    }

    public function tearDown(): void
    {
        Authorization::reset();
    }

    public function testCreateCollection()
    {
        $collection = self::$object->createDocument(Database::COLLECTION_COLLECTIONS, [
            '$collection' => Database::COLLECTION_COLLECTIONS,
            '$permissions' => ['read' => ['*']],
            'name' => 'Create',
        ]);

        $this->assertEquals(true, self::$object->createCollection($collection->getId(), [], []));
        
        try {
            self::$object->createCollection($collection->getId(), [], []);
        }
        catch (\Throwable $th) {
            return $this->assertEquals('42S01', $th->getCode());
        }

        throw new Exception('Expected exception');
    }

    public function testDeleteCollection()
    {

        $collection = self::$object->createDocument(Database::COLLECTION_COLLECTIONS, [
            '$collection' => Database::COLLECTION_COLLECTIONS,
            '$permissions' => ['read' => ['*']],
            'name' => 'Delete',
        ]);

        $this->assertEquals(true, self::$object->createCollection($collection->getId(), [], []));
        $this->assertEquals(true, self::$object->deleteCollection($collection->getId()));
        
        try {
            self::$object->deleteCollection($collection->getId());
        }
        catch (\Throwable $th) {
            return $this->assertEquals('42S02', $th->getCode());
        }

        throw new Exception('Expected exception');
    }

    public function testCreateAttribute()
    {
        // $this->assertEquals(true, self::$object->createCollection(self::$collection->getId(), [], []));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'title', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'description', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'numeric', Database::VAR_NUMERIC));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'integer', Database::VAR_INTEGER));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'float', Database::VAR_FLOAT));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'boolean', Database::VAR_BOOLEAN));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'document', Database::VAR_DOCUMENT));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'email', Database::VAR_EMAIL));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'url', Database::VAR_URL));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'ipv4', Database::VAR_IPV4));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'ipv6', Database::VAR_IPV6));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'key', Database::VAR_KEY));
        
        // // arrays
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'titles', Database::VAR_TEXT, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'descriptions', Database::VAR_TEXT, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'numerics', Database::VAR_NUMERIC, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'integers', Database::VAR_INTEGER, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'floats', Database::VAR_FLOAT, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'booleans', Database::VAR_BOOLEAN, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'documents', Database::VAR_DOCUMENT, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'emails', Database::VAR_EMAIL, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'urls', Database::VAR_URL, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'ipv4s', Database::VAR_IPV4, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'ipv6s', Database::VAR_IPV6, true));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'keys', Database::VAR_KEY, true));
    }

    // public function testDeleteAttribute()
    // {
    //     $this->assertEquals(true, self::$object->createCollection(self::$collection->getId(), [], []));
        
    //     $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'title', Database::VAR_TEXT));
    //     $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'description', Database::VAR_TEXT));
    //     $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'value', Database::VAR_NUMERIC));

    //     $this->assertEquals(true, self::$object->deleteAttribute(self::$collection->getId(), 'title', false));
    //     $this->assertEquals(true, self::$object->deleteAttribute(self::$collection->getId(), 'description', false));
    //     $this->assertEquals(true, self::$object->deleteAttribute(self::$collection->getId(), 'value', false));

    //     $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'titles', Database::VAR_TEXT, true));
    //     $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'descriptions', Database::VAR_TEXT, true));
    //     $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'values', Database::VAR_NUMERIC, true));

    //     $this->assertEquals(true, self::$object->deleteAttribute(self::$collection->getId(), 'titles', true));
    //     $this->assertEquals(true, self::$object->deleteAttribute(self::$collection->getId(), 'descriptions', true));
    //     $this->assertEquals(true, self::$object->deleteAttribute(self::$collection->getId(), 'values', true));
    // }

    public function testCreateIndex()
    {
        // $this->assertEquals(true, self::$object->createCollection(self::$collection->getId(), [], []));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'title', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'description', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createIndex(self::$collection->getId(), 'x', Database::INDEX_KEY, ['title']));
        // $this->assertEquals(true, self::$object->createIndex(self::$collection->getId(), 'y', Database::INDEX_KEY, ['description']));
        // $this->assertEquals(true, self::$object->createIndex(self::$collection->getId(), 'z', Database::INDEX_KEY, ['title', 'description']));
    }

    public function testDeleteIndex()
    {
        // $this->assertEquals(true, self::$object->createCollection(self::$collection->getId(), [], []));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'title', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createAttribute(self::$collection->getId(), 'description', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createIndex(self::$collection->getId(), 'x', Database::INDEX_KEY, ['title']));
        // $this->assertEquals(true, self::$object->createIndex(self::$collection->getId(), 'y', Database::INDEX_KEY, ['description']));
        // $this->assertEquals(true, self::$object->createIndex(self::$collection->getId(), 'z', Database::INDEX_KEY, ['title', 'description']));
        
        // $this->assertEquals(true, self::$object->deleteIndex(self::$collection->getId(), 'x'));
        // $this->assertEquals(true, self::$object->deleteIndex(self::$collection->getId(), 'y'));
        // $this->assertEquals(true, self::$object->deleteIndex(self::$collection->getId(), 'z'));
    }

    public function testCreateDocument()
    {
        // $this->assertEquals(true, self::$object->createCollection('create_document_'.self::$collection->getId(), [], []));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'title', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'description', Database::VAR_TEXT));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'numeric', Database::VAR_NUMERIC));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'integer', Database::VAR_INTEGER));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'float', Database::VAR_FLOAT));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'boolean', Database::VAR_BOOLEAN));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'email', Database::VAR_EMAIL));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'url', Database::VAR_URL));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'ipv4', Database::VAR_IPV4));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'ipv6', Database::VAR_IPV6));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'key', Database::VAR_KEY));
        
        // // arrays
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'titles', Database::VAR_TEXT, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'descriptions', Database::VAR_TEXT, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'numerics', Database::VAR_NUMERIC, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'integers', Database::VAR_INTEGER, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'floats', Database::VAR_FLOAT, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'booleans', Database::VAR_BOOLEAN, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'emails', Database::VAR_EMAIL, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'urls', Database::VAR_URL, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'ipv4s', Database::VAR_IPV4, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'ipv6s', Database::VAR_IPV6, true));
        // $this->assertEquals(true, self::$object->createAttribute('create_document_'.self::$collection->getId(), 'keys', Database::VAR_KEY, true));

        $collection = self::$object->createDocument(Database::COLLECTION_COLLECTIONS, [
            '$collection' => Database::COLLECTION_COLLECTIONS,
            '$permissions' => ['read' => ['*']],
            'name' => 'Create Documents',
            'rules' => [
                [
                    '$collection' => Database::COLLECTION_RULES,
                    '$permissions' => ['read' => ['*']],
                    'label' => 'Name',
                    'key' => 'name',
                    'type' => Database::VAR_TEXT,
                    'default' => '',
                    'required' => true,
                    'array' => false,
                ],
                [
                    '$collection' => Database::COLLECTION_RULES,
                    '$permissions' => ['read' => ['*']],
                    'label' => 'Links',
                    'key' => 'links',
                    'type' => Database::VAR_URL,
                    'default' => '',
                    'required' => true,
                    'array' => true,
                ],
            ]
        ]);

        $this->assertEquals(true, self::$object->createCollection($collection->getId(), [], []));
        
        $document = self::$object->createDocument($collection->getId(), [
            '$collection' => $collection->getId(),
            '$permissions' => [
                'read' => ['*'],
                'write' => ['user:123'],
            ],
            'name' => 'Task #1',
            'links' => [
                'http://example.com/link-1',
                'http://example.com/link-2',
                'http://example.com/link-3',
                'http://example.com/link-4',
            ],
        ]);

        $document = self::$object->createDocument(Database::COLLECTION_USERS, [
            '$collection' => Database::COLLECTION_USERS,
            '$permissions' => [
                'read' => ['*'],
                'write' => ['user:123'],
            ],
            'email' => 'test@appwrite.io',
            'emailVerification' => false,
            'status' => 0,
            'password' => 'secrethash',
            'password-update' => \time(),
            'registration' => \time(),
            'reset' => false,
            'name' => 'Test',
        ]);

        $this->assertNotEmpty($document->getId());
        $this->assertIsArray($document->getPermissions());
        $this->assertArrayHasKey('read', $document->getPermissions());
        $this->assertArrayHasKey('write', $document->getPermissions());
        $this->assertEquals('test@appwrite.io', $document->getAttribute('email'));
        $this->assertIsString($document->getAttribute('email'));
        $this->assertEquals(0, $document->getAttribute('status'));
        $this->assertIsInt($document->getAttribute('status'));
        $this->assertEquals(false, $document->getAttribute('emailVerification'));
        $this->assertIsBool($document->getAttribute('emailVerification'));

        // $document = self::$object->createDocument('create_document_'.self::$collection->getId(), [
        //     'title' => 'Hello World',
        //     'description' => 'I\'m a test document',
        //     'numeric' => 1,
        //     'integer' => 1,
        //     'float' => 2.22,
        //     'boolean' => true,
        //     'email' => 'test@appwrite.io',
        //     'url' => 'http://example.com/welcome',
        //     'ipv4' => '172.16.254.1',
        //     'ipv6' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
        //     'key' => uniqid(),
        // ]);

        $types = [
            Database::VAR_TEXT,
            Database::VAR_INTEGER,
            Database::VAR_FLOAT,
            Database::VAR_NUMERIC,
            Database::VAR_BOOLEAN,
            // Database::VAR_DOCUMENT,
            Database::VAR_EMAIL,
            Database::VAR_URL,
            Database::VAR_IPV4,
            Database::VAR_IPV6,
            Database::VAR_KEY,
        ];

        $rules = [];

        foreach($types as $type) {
            $rules[] = [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => ucfirst($type),
                'key' => $type,
                'type' => $type,
                'default' => null,
                'required' => true,
                'array' => false,
            ];

            $rules[] = [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => ucfirst($type),
                'key' => $type.'s',
                'type' => $type,
                'default' => null,
                'required' => true,
                'array' => true,
            ];
        }

        $collection = self::$object->createDocument(Database::COLLECTION_COLLECTIONS, [
            '$collection' => Database::COLLECTION_COLLECTIONS,
            '$permissions' => ['read' => ['*']],
            'name' => 'Create Documents',
            'rules' => $rules,
        ]);

        $this->assertEquals(true, self::$object->createCollection($collection->getId(), [], []));
        
        $document = self::$object->createDocument($collection->getId(), [
            '$collection' => $collection->getId(),
            '$permissions' => [
                'read' => ['*'],
                'write' => ['user:123'],
            ],
            'text' => 'Hello World',
            'texts' => ['Hello World 1', 'Hello World 2'],
            'integer' => 1,
            'integers' => [5, 3, 4],
            'float' => 2.22,
            'floats' => [1.13, 4.33, 8.9999],
            'numeric' => 1,
            'numerics' => [1, 5, 7.77],
            'boolean' => true,
            'booleans' => [true, false, true],
            'email' => 'test@appwrite.io',
            'emails' => [
                'test4@appwrite.io',
                'test3@appwrite.io',
                'test2@appwrite.io',
                'test1@appwrite.io'
            ],
            'url' => 'http://example.com/welcome',
            'urls' => [
                'http://example.com/welcome-1',
                'http://example.com/welcome-2',
                'http://example.com/welcome-3'
            ],
            'ipv4' => '172.16.254.1',
            'ipv4s' => [
                '172.16.254.1',
                '172.16.254.5'
            ],
            'ipv6' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            'ipv6s' => [
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                '2001:0db8:85a3:0000:0000:8a2e:0370:7337'
            ],
            'key' => uniqid(),
            'keys' => [uniqid(), uniqid(), uniqid()],
        ]);

        $document = self::$object->getDocument($collection->getId(), $document->getId());

        $this->assertIsString($document->getId());
        $this->assertIsString($document->getCollection());
        $this->assertEquals([
            'read' => ['*'],
            'write' => ['user:123'],
        ], $document->getPermissions());
        $this->assertEquals('Hello World', $document->getAttribute('text'));
        $this->assertCount(2, $document->getAttribute('texts'));
        
        $this->assertEquals('Hello World', $document->getAttribute('text'));
        $this->assertEquals(['Hello World 1', 'Hello World 2'], $document->getAttribute('texts'));
        $this->assertCount(2, $document->getAttribute('texts'));
        
        $this->assertEquals(1, $document->getAttribute('integer'));
        $this->assertEquals([5, 3, 4], $document->getAttribute('integers'));
        $this->assertCount(3, $document->getAttribute('integers'));

        $this->assertEquals(2.22, $document->getAttribute('float'));
        $this->assertEquals([1.13, 4.33, 8.9999], $document->getAttribute('floats'));
        $this->assertCount(3, $document->getAttribute('floats'));

        $this->assertEquals(true, $document->getAttribute('boolean'));
        $this->assertEquals([true, false, true], $document->getAttribute('booleans'));
        $this->assertCount(3, $document->getAttribute('booleans'));

        $this->assertEquals('test@appwrite.io', $document->getAttribute('email'));
        $this->assertEquals([
            'test4@appwrite.io',
            'test3@appwrite.io',
            'test2@appwrite.io',
            'test1@appwrite.io'
        ], $document->getAttribute('emails'));
        $this->assertCount(4, $document->getAttribute('emails'));

        $this->assertEquals('http://example.com/welcome', $document->getAttribute('url'));
        $this->assertEquals([
            'http://example.com/welcome-1',
            'http://example.com/welcome-2',
            'http://example.com/welcome-3'
        ], $document->getAttribute('urls'));
        $this->assertCount(3, $document->getAttribute('urls'));

        $this->assertEquals('172.16.254.1', $document->getAttribute('ipv4'));
        $this->assertEquals([
            '172.16.254.1',
            '172.16.254.5'
        ], $document->getAttribute('ipv4s'));
        $this->assertCount(2, $document->getAttribute('ipv4s'));

        $this->assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $document->getAttribute('ipv6'));
        $this->assertEquals([
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7337'
        ], $document->getAttribute('ipv6s'));
        $this->assertCount(2, $document->getAttribute('ipv6s'));

        $this->assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $document->getAttribute('ipv6'));
        $this->assertEquals([
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7337'
        ], $document->getAttribute('ipv6s'));
        $this->assertCount(2, $document->getAttribute('ipv6s'));

        $this->assertIsString($document->getAttribute('key'));
        $this->assertCount(3, $document->getAttribute('keys'));
    }

    public function testGetMockDocument()
    {
        $document = self::$object->getDocument(Database::COLLECTION_COLLECTIONS, Database::COLLECTION_USERS);

        $this->assertEquals(Database::COLLECTION_USERS, $document->getId());
        $this->assertEquals(Database::COLLECTION_COLLECTIONS, $document->getCollection());
    }

    public function testGetDocument()
    {
        $this->assertEquals('1', '1');
    }

    public function testUpdateDocument()
    {
        $this->assertEquals('1', '1');
    }

    public function testDeleteDocument()
    {
        $this->assertEquals('1', '1');
    }

    public function testFind()
    {
        $this->assertEquals('1', '1');
    }

    public function testFindFirst()
    {
        $this->assertEquals('1', '1');
    }

    public function testFindLast()
    {
        $this->assertEquals('1', '1');
    }

    public function countTest()
    {
        $this->assertEquals('1', '1');
    }

    public function addFilterTest()
    {
        $this->assertEquals('1', '1');
    }

    public function encodeTest()
    {
        $this->assertEquals('1', '1');
    }

    public function decodeTest()
    {
        $this->assertEquals('1', '1');
    }
}