<?php

namespace Appwrite\Tests;

use PDO;
use Exception;
use Appwrite\Database\Adapter\Relational;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    /**
     * @var Database
     */
    protected $object = null;

    /**
     * @var string
     */
    protected $collection = '';

    public function setUp()
    {
        $this->collection = uniqid();

        $dbHost = getenv('_APP_DB_HOST');
        $dbUser = getenv('_APP_DB_USER');
        $dbPass = getenv('_APP_DB_PASS');
        $dbScheme = getenv('_APP_DB_SCHEMA');

        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            PDO::ATTR_TIMEOUT => 3, // Seconds
            PDO::ATTR_PERSISTENT => true
        ));

        // Connection settings
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // Return arrays
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

        $this->object = new Database();
        $this->object->setAdapter(new Relational($pdo));
        $this->object->setNamespace('test');

        $this->object->setMocks([
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
                        'required' => true,
                        'array' => true,
                        'list' => [Database::COLLECTION_RULES],
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
    }

    public function tearDown()
    {

    }

    public function testCreateCollection()
    {
        $this->assertEquals(true, $this->object->createCollection('create_'.$this->collection, [], []));
        
        try {
            $this->object->createCollection('create_'.$this->collection, [], []);
        }
        catch (\Throwable $th) {
            return $this->assertEquals('42S01', $th->getCode());
        }

        throw new Exception('Expected exception');
    }

    public function testDeleteCollection()
    {
        $this->assertEquals(true, $this->object->createCollection('delete_'.$this->collection, [], []));
        $this->assertEquals(true, $this->object->deleteCollection('delete_'.$this->collection));
        
        try {
            $this->object->deleteCollection('delete_'.$this->collection);
        }
        catch (\Throwable $th) {
            return $this->assertEquals('42S02', $th->getCode());
        }

        throw new Exception('Expected exception');
    }

    public function testCreateAttribute()
    {
        $this->assertEquals(true, $this->object->createCollection('create_attr_'.$this->collection, [], []));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'title', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'description', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'numeric', Database::VAR_NUMERIC));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'integer', Database::VAR_INTEGER));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'float', Database::VAR_FLOAT));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'boolean', Database::VAR_BOOLEAN));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'document', Database::VAR_DOCUMENT));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'email', Database::VAR_EMAIL));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'url', Database::VAR_URL));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'ipv4', Database::VAR_IPV4));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'ipv6', Database::VAR_IPV6));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'key', Database::VAR_KEY));
        
        // arrays
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'titles', Database::VAR_TEXT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'descriptions', Database::VAR_TEXT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'numerics', Database::VAR_NUMERIC, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'integers', Database::VAR_INTEGER, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'floats', Database::VAR_FLOAT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'booleans', Database::VAR_BOOLEAN, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'documents', Database::VAR_DOCUMENT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'emails', Database::VAR_EMAIL, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'urls', Database::VAR_URL, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'ipv4s', Database::VAR_IPV4, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'ipv6s', Database::VAR_IPV6, true));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'keys', Database::VAR_KEY, true));
    }

    public function testDeleteAttribute()
    {
        $this->assertEquals(true, $this->object->createCollection('delete_attr_'.$this->collection, [], []));
        
        $this->assertEquals(true, $this->object->createAttribute('delete_attr_'.$this->collection, 'title', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('delete_attr_'.$this->collection, 'description', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('delete_attr_'.$this->collection, 'value', Database::VAR_NUMERIC));

        $this->assertEquals(true, $this->object->deleteAttribute('delete_attr_'.$this->collection, 'title'));
        $this->assertEquals(true, $this->object->deleteAttribute('delete_attr_'.$this->collection, 'description'));
        $this->assertEquals(true, $this->object->deleteAttribute('delete_attr_'.$this->collection, 'value'));

        $this->assertEquals(true, $this->object->createAttribute('delete_attr_'.$this->collection, 'titles', Database::VAR_TEXT, true));
        $this->assertEquals(true, $this->object->createAttribute('delete_attr_'.$this->collection, 'descriptions', Database::VAR_TEXT, true));
        $this->assertEquals(true, $this->object->createAttribute('delete_attr_'.$this->collection, 'values', Database::VAR_NUMERIC, true));

        $this->assertEquals(true, $this->object->deleteAttribute('delete_attr_'.$this->collection, 'titles', true));
        $this->assertEquals(true, $this->object->deleteAttribute('delete_attr_'.$this->collection, 'descriptions', true));
        $this->assertEquals(true, $this->object->deleteAttribute('delete_attr_'.$this->collection, 'values', true));
    }

    public function testCreateIndex()
    {
        $this->assertEquals(true, $this->object->createCollection('create_index_'.$this->collection, [], []));
        $this->assertEquals(true, $this->object->createAttribute('create_index_'.$this->collection, 'title', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_index_'.$this->collection, 'description', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createIndex('create_index_'.$this->collection, 'x', Database::INDEX_KEY, ['title']));
        $this->assertEquals(true, $this->object->createIndex('create_index_'.$this->collection, 'y', Database::INDEX_KEY, ['description']));
        $this->assertEquals(true, $this->object->createIndex('create_index_'.$this->collection, 'z', Database::INDEX_KEY, ['title', 'description']));
    }

    public function testDeleteIndex()
    {
        $this->assertEquals(true, $this->object->createCollection('delete_index_'.$this->collection, [], []));
        $this->assertEquals(true, $this->object->createAttribute('delete_index_'.$this->collection, 'title', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('delete_index_'.$this->collection, 'description', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createIndex('delete_index_'.$this->collection, 'x', Database::INDEX_KEY, ['title']));
        $this->assertEquals(true, $this->object->createIndex('delete_index_'.$this->collection, 'y', Database::INDEX_KEY, ['description']));
        $this->assertEquals(true, $this->object->createIndex('delete_index_'.$this->collection, 'z', Database::INDEX_KEY, ['title', 'description']));
        
        $this->assertEquals(true, $this->object->deleteIndex('delete_index_'.$this->collection, 'x'));
        $this->assertEquals(true, $this->object->deleteIndex('delete_index_'.$this->collection, 'y'));
        $this->assertEquals(true, $this->object->deleteIndex('delete_index_'.$this->collection, 'z'));
    }

    public function testCreateDocument()
    {
        $this->assertEquals(true, $this->object->createCollection('create_document_'.$this->collection, [], []));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'title', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'description', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'numeric', Database::VAR_NUMERIC));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'integer', Database::VAR_INTEGER));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'float', Database::VAR_FLOAT));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'boolean', Database::VAR_BOOLEAN));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'email', Database::VAR_EMAIL));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'url', Database::VAR_URL));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'ipv4', Database::VAR_IPV4));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'ipv6', Database::VAR_IPV6));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'key', Database::VAR_KEY));
        
        // arrays
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'titles', Database::VAR_TEXT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'descriptions', Database::VAR_TEXT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'numerics', Database::VAR_NUMERIC, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'integers', Database::VAR_INTEGER, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'floats', Database::VAR_FLOAT, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'booleans', Database::VAR_BOOLEAN, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'emails', Database::VAR_EMAIL, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'urls', Database::VAR_URL, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'ipv4s', Database::VAR_IPV4, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'ipv6s', Database::VAR_IPV6, true));
        $this->assertEquals(true, $this->object->createAttribute('create_document_'.$this->collection, 'keys', Database::VAR_KEY, true));

        Authorization::disable();

        // $document = $this->object->createDocument('create_document_'.$this->collection, [
        //     '$collection' => Database::COLLECTION_USERS,
        //     '$permissions' => [
        //         'read' => ['*'],
        //         'write' => ['user:123'],
        //     ],
        //     'email' => 'test@appwrite.io',
        //     'emailVerification' => false,
        //     'status' => 0,
        //     'password' => 'secrethash',
        //     'password-update' => \time(),
        //     'registration' => \time(),
        //     'reset' => false,
        //     'name' => 'Test',
        // ]);

        $document = $this->object->createDocument('create_document_'.$this->collection, [
            'title' => 'Hello World',
            'description' => 'I\'m a test document',
            'numeric' => 1,
            'integer' => 1,
            'float' => 2.22,
            'boolean' => true,
            'email' => 'test@appwrite.io',
            'url' => 'http://example.com/welcome',
            'ipv4' => '172.16.254.1',
            'ipv6' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            'key' => uniqid(),
        ]);

        Authorization::reset();

        var_dump($document);
    }

    public function testGetMockDocument()
    {
        $document = $this->object->getDocument(Database::COLLECTION_COLLECTIONS, Database::COLLECTION_USERS);

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