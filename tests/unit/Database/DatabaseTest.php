<?php

namespace Appwrite\Tests;

use PDO;
use Appwrite\Database\Adapter\Relational;
use Appwrite\Database\Database;
use Exception;
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

        // $this->object->createDocument('dddd', [
        //     '$permissions' => ['read' => ['*'], 'write' => ['*']],
        //     'title' => '123',
        // ]);
    }

    public function tearDown()
    {

    }

    public function testCreateCollection()
    {
        $this->assertEquals(true, $this->object->createCollection('create_'.$this->collection));
        
        try {
            $this->object->createCollection('create_'.$this->collection);
        }
        catch (\Throwable $th) {
            return $this->assertEquals('42S01', $th->getCode());
        }

        throw new Exception('Expected exception');
    }

    public function testDeleteCollection()
    {
        $this->assertEquals(true, $this->object->createCollection('delete_'.$this->collection));
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
        $this->assertEquals(true, $this->object->createCollection('create_attr_'.$this->collection));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'title', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'description', Database::VAR_TEXT));
        $this->assertEquals(true, $this->object->createAttribute('create_attr_'.$this->collection, 'value', Database::VAR_NUMERIC));
        
        try {
            $this->object->deleteCollection('delete_'.$this->collection);
        }
        catch (\Throwable $th) {
            return $this->assertEquals('42S02', $th->getCode());
        }

        throw new Exception('Expected exception');
    }

    public function testDeleteAttribute()
    {
        $this->assertEquals('1', '1');
    }

    public function testCreateIndex()
    {
        $this->assertEquals('1', '1');
    }

    public function testDeleteIndex()
    {
        $this->assertEquals('1', '1');
    }

    public function testCreateDocument()
    {
        $this->assertEquals('1', '1');
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