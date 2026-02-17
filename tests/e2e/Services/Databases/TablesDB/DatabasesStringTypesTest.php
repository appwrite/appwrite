<?php

namespace Tests\E2E\Services\Databases\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SchemaPolling;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Traits\DatabasesUrlHelpers;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesStringTypesTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use ApiTablesDB;
    use DatabasesUrlHelpers;
    use SchemaPolling;

    private static array $setupCache = [];

    /**
     * Setup database, table, and all columns for parallel-safe tests.
     */
    protected function setupDatabaseAndTable(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(static::$setupCache[$cacheKey])) {
            return static::$setupCache[$cacheKey];
        }

        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'String Types Test Database'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
            'tableId' => ID::unique(),
            'name' => 'String Types Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create varchar columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', $headers, [
            'key' => 'varchar_field', 'size' => 255, 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', $headers, [
            'key' => 'varchar_with_default', 'size' => 100, 'required' => false, 'default' => 'hello world',
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', $headers, [
            'key' => 'varchar_required', 'size' => 50, 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', $headers, [
            'key' => 'varchar_array', 'size' => 64, 'required' => false, 'array' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', $headers, [
            'key' => 'varchar_min', 'size' => 1, 'required' => false,
        ]);

        sleep(1);

        // Create text columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', $headers, [
            'key' => 'text_field', 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', $headers, [
            'key' => 'text_with_default', 'required' => false, 'default' => 'This is a longer default text value that can contain more content.',
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', $headers, [
            'key' => 'text_required', 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', $headers, [
            'key' => 'text_array', 'required' => false, 'array' => true,
        ]);

        sleep(1);

        // Create mediumtext columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', $headers, [
            'key' => 'mediumtext_field', 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', $headers, [
            'key' => 'mediumtext_with_default', 'required' => false, 'default' => 'Default mediumtext content',
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', $headers, [
            'key' => 'mediumtext_required', 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', $headers, [
            'key' => 'mediumtext_array', 'required' => false, 'array' => true,
        ]);

        sleep(1);

        // Create longtext columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', $headers, [
            'key' => 'longtext_field', 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', $headers, [
            'key' => 'longtext_with_default', 'required' => false, 'default' => 'Default longtext content for very large text storage',
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', $headers, [
            'key' => 'longtext_required', 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', $headers, [
            'key' => 'longtext_array', 'required' => false, 'array' => true,
        ]);

        // Wait for all columns to be available
        $this->waitForAllAttributes($databaseId, $tableId);

        static::$setupCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
        ];

        return static::$setupCache[$cacheKey];
    }

    public function testCreateDatabase(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'String Types Test Database'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
    }

    public function testCreateTable(): void
    {
        $data = $this->setupDatabaseAndTable();

        $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['tableId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals($data['tableId'], $table['body']['$id']);
    }

    public function testCreateVarcharColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Verify varchar columns were created correctly
        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('varchar_field', $column['body']['key']);
        $this->assertEquals('varchar', $column['body']['type']);
        $this->assertEquals(255, $column['body']['size']);
        $this->assertEquals(false, $column['body']['required']);
        $this->assertNull($column['body']['default']);
        $this->assertFalse($column['body']['encrypt']);

        // Verify varchar with default
        $columnDefault = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnDefault['headers']['status-code']);
        $this->assertEquals('hello world', $columnDefault['body']['default']);

        // Verify required varchar
        $columnRequired = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnRequired['headers']['status-code']);
        $this->assertEquals(true, $columnRequired['body']['required']);

        // Verify array varchar
        $columnArray = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnArray['headers']['status-code']);
        $this->assertEquals(true, $columnArray['body']['array']);

        // Verify min size varchar
        $columnMin = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnMin['headers']['status-code']);
        $this->assertEquals(1, $columnMin['body']['size']);

        // Test SUCCESS: Create encrypted varchar column
        $varcharEncrypted = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_encrypted',
            'size' => 256,
            'required' => false,
            'encrypt' => true,
        ]);

        $this->assertEquals(202, $varcharEncrypted['headers']['status-code']);
        $this->assertTrue($varcharEncrypted['body']['encrypt']);
    }

    public function testCreateVarcharColumnFailures(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test FAILURE: Size 0
        $varcharZero = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_zero',
            'size' => 0,
            'required' => false,
        ]);

        $this->assertEquals(400, $varcharZero['headers']['status-code']);

        // Test FAILURE: Negative size
        $varcharNegative = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_negative',
            'size' => -10,
            'required' => false,
        ]);

        $this->assertEquals(400, $varcharNegative['headers']['status-code']);

        // Test FAILURE: Size exceeds maximum (16382)
        $varcharTooLarge = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_too_large',
            'size' => 16382,
            'required' => false,
        ]);

        $this->assertEquals(400, $varcharTooLarge['headers']['status-code']);

        // Test FAILURE: Missing size parameter
        $varcharNoSize = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_no_size',
            'required' => false,
        ]);

        $this->assertEquals(400, $varcharNoSize['headers']['status-code']);

        // Test FAILURE: Default value exceeds size
        $varcharDefaultTooLong = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_default_too_long',
            'size' => 5,
            'required' => false,
            'default' => 'this is way too long for the size',
        ]);

        $this->assertEquals(400, $varcharDefaultTooLong['headers']['status-code']);

        // Test FAILURE: Duplicate key
        $varcharDuplicate = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_field', // Already exists
            'size' => 100,
            'required' => false,
        ]);

        $this->assertEquals(409, $varcharDuplicate['headers']['status-code']);

        // Test FAILURE: Encrypted varchar with size too small
        $varcharEncryptTooSmall = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_encrypt_small',
            'size' => 149,
            'required' => false,
            'encrypt' => true,
        ]);

        $this->assertEquals(400, $varcharEncryptTooSmall['headers']['status-code']);
    }

    public function testCreateTextColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('text_field', $column['body']['key']);
        $this->assertEquals('text', $column['body']['type']);
        $this->assertEquals(false, $column['body']['required']);

        // Verify text with default
        $columnDefault = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnDefault['headers']['status-code']);
        $this->assertEquals('This is a longer default text value that can contain more content.', $columnDefault['body']['default']);

        // Verify required text
        $columnRequired = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnRequired['headers']['status-code']);
        $this->assertEquals(true, $columnRequired['body']['required']);

        // Verify text array
        $columnArray = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnArray['headers']['status-code']);
        $this->assertEquals(true, $columnArray['body']['array']);
        // Test SUCCESS: Create encrypted text column
        $textEncrypted = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'text_encrypted',
            'required' => false,
            'encrypt' => true,
        ]);

        $this->assertEquals(202, $textEncrypted['headers']['status-code']);
        $this->assertTrue($textEncrypted['body']['encrypt']);


    }

    public function testCreateMediumtextColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('mediumtext_field', $column['body']['key']);
        $this->assertEquals('mediumtext', $column['body']['type']);
        $this->assertEquals(false, $column['body']['required']);

        // Verify mediumtext with default
        $columnDefault = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnDefault['headers']['status-code']);

        // Verify required mediumtext
        $columnRequired = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnRequired['headers']['status-code']);
        $this->assertEquals(true, $columnRequired['body']['required']);

        // Verify mediumtext array
        $columnArray = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnArray['headers']['status-code']);
        $this->assertEquals(true, $columnArray['body']['array']);

        // Test SUCCESS: Create encrypted mediumtext column
        $mediumtextEncrypted = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'mediumtext_encrypted',
            'required' => false,
            'encrypt' => true,
        ]);

        $this->assertEquals(202, $mediumtextEncrypted['headers']['status-code']);
        $this->assertTrue($mediumtextEncrypted['body']['encrypt']);
    }

    public function testCreateLongtextColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('longtext_field', $column['body']['key']);
        $this->assertEquals('longtext', $column['body']['type']);
        $this->assertEquals(false, $column['body']['required']);

        // Verify longtext with default
        $columnDefault = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnDefault['headers']['status-code']);

        // Verify required longtext
        $columnRequired = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnRequired['headers']['status-code']);
        $this->assertEquals(true, $columnRequired['body']['required']);

        // Verify longtext array
        $columnArray = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columnArray['headers']['status-code']);
        $this->assertEquals(true, $columnArray['body']['array']);

        // Test SUCCESS: Create encrypted longtext column
        $longtextEncrypted = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'longtext_encrypted',
            'required' => false,
            'encrypt' => true,
        ]);

        $this->assertEquals(202, $longtextEncrypted['headers']['status-code']);
        $this->assertTrue($longtextEncrypted['body']['encrypt']);
    }

    public function testUpdateVarcharColumn(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports VARCHAR type');
    }

    public function testUpdateTextColumn(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports TEXT type');
    }

    public function testUpdateMediumtextColumn(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports MEDIUMTEXT type');
    }

    public function testUpdateLongtextColumn(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports LONGTEXT type');
    }

    public function testCreateRowWithStringTypes(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Create row with all string types
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'varchar_field' => 'Test varchar value',
                'varchar_required' => 'Required value',
                'text_field' => 'This is a text field with more content.',
                'text_required' => 'Required text',
                'mediumtext_field' => 'Medium text content here',
                'mediumtext_required' => 'Required mediumtext',
                'longtext_field' => 'Long text content for storing large amounts of data',
                'longtext_required' => 'Required longtext',
                'varchar_array' => ['item1', 'item2', 'item3'],
                'text_array' => ['text item 1', 'text item 2'],
                'mediumtext_array' => ['mediumtext item 1'],
                'longtext_array' => ['longtext item 1', 'longtext item 2'],
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertEquals('Test varchar value', $row['body']['varchar_field']);
        $this->assertEquals('Required value', $row['body']['varchar_required']);
        $this->assertEquals('This is a text field with more content.', $row['body']['text_field']);
        $this->assertEquals('Required text', $row['body']['text_required']);
        $this->assertEquals('Medium text content here', $row['body']['mediumtext_field']);
        $this->assertEquals('Long text content for storing large amounts of data', $row['body']['longtext_field']);
        $this->assertCount(3, $row['body']['varchar_array']);
        $this->assertCount(2, $row['body']['text_array']);
    }

    public function testCreateRowWithDefaultValues(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Create row using default values
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'varchar_field' => 'Value',
                'varchar_required' => 'Required',
                'text_required' => 'Required text',
                'mediumtext_required' => 'Required mediumtext',
                'longtext_required' => 'Required longtext',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $row['headers']['status-code']);
        // Check that default values are applied (original defaults, update tests are skipped)
        $this->assertEquals('hello world', $row['body']['varchar_with_default']);
        $this->assertEquals('This is a longer default text value that can contain more content.', $row['body']['text_with_default']);
    }

    public function testCreateRowFailures(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test FAILURE: Missing required field
        $rowMissingRequired = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'varchar_field' => 'Value',
                // Missing varchar_required, text_required, etc.
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(400, $rowMissingRequired['headers']['status-code']);
    }

    public function testGetVarcharColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('varchar_with_default', $column['body']['key']);
        $this->assertEquals('varchar', $column['body']['type']);
        $this->assertEquals(100, $column['body']['size']);
    }

    public function testGetTextColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('text_field', $column['body']['key']);
        $this->assertEquals('text', $column['body']['type']);
    }

    public function testGetMediumtextColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('mediumtext_field', $column['body']['key']);
        $this->assertEquals('mediumtext', $column['body']['type']);
    }

    public function testGetLongtextColumn(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $column = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals('longtext_field', $column['body']['key']);
        $this->assertEquals('longtext', $column['body']['type']);
    }

    public function testGetTableWithStringTypeColumns(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Get full table - verifies Table model serializes all string column types
        $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals($tableId, $table['body']['$id']);
        $this->assertIsArray($table['body']['columns']);

        // Extract column types from the response
        $columnTypes = array_map(fn ($col) => $col['type'], $table['body']['columns']);

        // Verify all new string types are present and properly serialized
        $this->assertContains('varchar', $columnTypes, 'Table response should contain varchar columns');
        $this->assertContains('text', $columnTypes, 'Table response should contain text columns');
        $this->assertContains('mediumtext', $columnTypes, 'Table response should contain mediumtext columns');
        $this->assertContains('longtext', $columnTypes, 'Table response should contain longtext columns');

        // Verify column keys are present
        $columnKeys = array_map(fn ($col) => $col['key'], $table['body']['columns']);
        $this->assertContains('varchar_field', $columnKeys);
        $this->assertContains('text_field', $columnKeys);
        $this->assertContains('mediumtext_field', $columnKeys);
        $this->assertContains('longtext_field', $columnKeys);
    }

    public function testListColumnsWithStringTypes(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: List all columns - verifies ColumnList model serializes all string column types
        $columns = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $columns['headers']['status-code']);
        $this->assertIsArray($columns['body']['columns']);
        $this->assertGreaterThan(0, $columns['body']['total']);

        // Extract column types from the response
        $columnTypes = array_map(fn ($col) => $col['type'], $columns['body']['columns']);

        // Verify all new string types are present and properly serialized
        $this->assertContains('varchar', $columnTypes, 'Column list should contain varchar columns');
        $this->assertContains('text', $columnTypes, 'Column list should contain text columns');
        $this->assertContains('mediumtext', $columnTypes, 'Column list should contain mediumtext columns');
        $this->assertContains('longtext', $columnTypes, 'Column list should contain longtext columns');
    }

    public function testDeleteStringTypeColumns(): void
    {
        $data = $this->setupDatabaseAndTable();
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Delete varchar column
        $deleteVarchar = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(204, $deleteVarchar['headers']['status-code']);

        // Wait for async deletion to complete
        sleep(2);

        // Verify deletion
        $getDeleted = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(404, $getDeleted['headers']['status-code']);
    }
}
