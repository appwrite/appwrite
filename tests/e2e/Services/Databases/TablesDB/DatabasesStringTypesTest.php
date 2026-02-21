<?php

namespace Tests\E2E\Services\Databases\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesStringTypesTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private static string $databaseId;
    private static string $tableId;

    public function testCreateDatabase(): array
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
        self::$databaseId = $database['body']['$id'];

        return ['databaseId' => $database['body']['$id']];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateTable(array $data): array
    {
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $data['databaseId'] . '/tables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'tableId' => ID::unique(),
            'name' => 'String Types Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        self::$tableId = $table['body']['$id'];

        return [
            'databaseId' => $data['databaseId'],
            'tableId' => $table['body']['$id'],
        ];
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateVarcharColumn(array $data): array
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Create varchar column with valid size
        $varchar = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_field',
            'size' => 255,
            'required' => false,
        ]);

        $this->assertEquals(202, $varchar['headers']['status-code']);
        $this->assertEquals('varchar_field', $varchar['body']['key']);
        $this->assertEquals('varchar', $varchar['body']['type']);
        $this->assertEquals(255, $varchar['body']['size']);
        $this->assertEquals(false, $varchar['body']['required']);
        $this->assertNull($varchar['body']['default']);
        $this->assertFalse($varchar['body']['encrypt']);

        // Test SUCCESS: Create varchar with default value
        $varcharWithDefault = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_with_default',
            'size' => 100,
            'required' => false,
            'default' => 'hello world',
        ]);

        $this->assertEquals(202, $varcharWithDefault['headers']['status-code']);
        $this->assertEquals('hello world', $varcharWithDefault['body']['default']);

        // Test SUCCESS: Create required varchar
        $varcharRequired = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_required',
            'size' => 50,
            'required' => true,
        ]);

        $this->assertEquals(202, $varcharRequired['headers']['status-code']);
        $this->assertEquals(true, $varcharRequired['body']['required']);

        // Test SUCCESS: Create varchar array
        $varcharArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_array',
            'size' => 64,
            'required' => false,
            'array' => true,
        ]);

        $this->assertEquals(202, $varcharArray['headers']['status-code']);
        $this->assertEquals(true, $varcharArray['body']['array']);

        // Test SUCCESS: Minimum varchar size (1)
        $varcharMin = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_min',
            'size' => 1,
            'required' => false,
        ]);

        $this->assertEquals(202, $varcharMin['headers']['status-code']);
        $this->assertEquals(1, $varcharMin['body']['size']);

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

        return $data;
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateVarcharColumnFailures(array $data): void
    {
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

    /**
     * @depends testCreateTable
     */
    public function testCreateTextColumn(array $data): array
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Create text column
        $text = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'text_field',
            'required' => false,
        ]);

        $this->assertEquals(202, $text['headers']['status-code']);
        $this->assertEquals('text_field', $text['body']['key']);
        $this->assertEquals('text', $text['body']['type']);
        $this->assertEquals(false, $text['body']['required']);
        $this->assertFalse($text['body']['encrypt']);

        // Test SUCCESS: Create text with default value
        $textWithDefault = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'text_with_default',
            'required' => false,
            'default' => 'This is a longer default text value that can contain more content.',
        ]);

        $this->assertEquals(202, $textWithDefault['headers']['status-code']);
        $this->assertEquals('This is a longer default text value that can contain more content.', $textWithDefault['body']['default']);

        // Test SUCCESS: Create required text
        $textRequired = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'text_required',
            'required' => true,
        ]);

        $this->assertEquals(202, $textRequired['headers']['status-code']);
        $this->assertEquals(true, $textRequired['body']['required']);

        // Test SUCCESS: Create text array
        $textArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'text_array',
            'required' => false,
            'array' => true,
        ]);

        $this->assertEquals(202, $textArray['headers']['status-code']);
        $this->assertEquals(true, $textArray['body']['array']);

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

        return $data;
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateMediumtextColumn(array $data): array
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Create mediumtext column
        $mediumtext = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'mediumtext_field',
            'required' => false,
        ]);

        $this->assertEquals(202, $mediumtext['headers']['status-code']);
        $this->assertEquals('mediumtext_field', $mediumtext['body']['key']);
        $this->assertEquals('mediumtext', $mediumtext['body']['type']);
        $this->assertEquals(false, $mediumtext['body']['required']);
        $this->assertFalse($mediumtext['body']['encrypt']);

        // Test SUCCESS: Create mediumtext with default
        $mediumtextWithDefault = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'mediumtext_with_default',
            'required' => false,
            'default' => 'Default mediumtext content',
        ]);

        $this->assertEquals(202, $mediumtextWithDefault['headers']['status-code']);

        // Test SUCCESS: Create required mediumtext
        $mediumtextRequired = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'mediumtext_required',
            'required' => true,
        ]);

        $this->assertEquals(202, $mediumtextRequired['headers']['status-code']);
        $this->assertEquals(true, $mediumtextRequired['body']['required']);

        // Test SUCCESS: Create mediumtext array
        $mediumtextArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'mediumtext_array',
            'required' => false,
            'array' => true,
        ]);

        $this->assertEquals(202, $mediumtextArray['headers']['status-code']);
        $this->assertEquals(true, $mediumtextArray['body']['array']);

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

        return $data;
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateLongtextColumn(array $data): array
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Create longtext column
        $longtext = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'longtext_field',
            'required' => false,
        ]);

        $this->assertEquals(202, $longtext['headers']['status-code']);
        $this->assertEquals('longtext_field', $longtext['body']['key']);
        $this->assertEquals('longtext', $longtext['body']['type']);
        $this->assertEquals(false, $longtext['body']['required']);
        $this->assertFalse($longtext['body']['encrypt']);

        // Test SUCCESS: Create longtext with default
        $longtextWithDefault = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'longtext_with_default',
            'required' => false,
            'default' => 'Default longtext content for very large text storage',
        ]);

        $this->assertEquals(202, $longtextWithDefault['headers']['status-code']);

        // Test SUCCESS: Create required longtext
        $longtextRequired = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'longtext_required',
            'required' => true,
        ]);

        $this->assertEquals(202, $longtextRequired['headers']['status-code']);
        $this->assertEquals(true, $longtextRequired['body']['required']);

        // Test SUCCESS: Create longtext array
        $longtextArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'longtext_array',
            'required' => false,
            'array' => true,
        ]);

        $this->assertEquals(202, $longtextArray['headers']['status-code']);
        $this->assertEquals(true, $longtextArray['body']['array']);

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

        return $data;
    }

    /**
     * @depends testCreateLongtextColumn
     */
    public function testUpdateVarcharColumn(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports VARCHAR type');

        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Wait for columns to be created
        sleep(3);

        // Test SUCCESS: Update varchar default value
        $update = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar/varchar_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'required' => false,
            'default' => 'updated default',
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('updated default', $update['body']['default']);

        // Test SUCCESS: Update varchar to make it required (no default)
        $updateRequired = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar/varchar_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'required' => true,
            'default' => null,
        ]);

        $this->assertEquals(200, $updateRequired['headers']['status-code']);
        $this->assertEquals(true, $updateRequired['body']['required']);

        // Test SUCCESS: Update varchar key (rename)
        $updateKey = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'required' => false,
            'default' => null,
            'newKey' => 'varchar_renamed',
        ]);

        $this->assertEquals(200, $updateKey['headers']['status-code']);
        $this->assertEquals('varchar_renamed', $updateKey['body']['key']);

        return $data;
    }

    /**
     * @depends testUpdateVarcharColumn
     */
    public function testUpdateTextColumn(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports TEXT type');

        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Update text default value
        $update = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/text/text_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'required' => false,
            'default' => 'Updated text default value',
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('Updated text default value', $update['body']['default']);

        return $data;
    }

    /**
     * @depends testUpdateTextColumn
     */
    public function testUpdateMediumtextColumn(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports MEDIUMTEXT type');

        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Update mediumtext default value
        $update = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/mediumtext/mediumtext_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'required' => false,
            'default' => 'Updated mediumtext default',
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('Updated mediumtext default', $update['body']['default']);

        return $data;
    }

    /**
     * @depends testUpdateMediumtextColumn
     */
    public function testUpdateLongtextColumn(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports LONGTEXT type');

        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Update longtext default value
        $update = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/longtext/longtext_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'required' => false,
            'default' => 'Updated longtext default',
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertEquals('Updated longtext default', $update['body']['default']);

        return $data;
    }

    /**
     * @depends testUpdateLongtextColumn
     */
    public function testCreateRowWithStringTypes(array $data): array
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Wait for all columns to be available
        sleep(2);

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

        return array_merge($data, ['rowId' => $row['body']['$id']]);
    }

    /**
     * @depends testCreateRowWithStringTypes
     */
    public function testCreateRowWithDefaultValues(array $data): void
    {
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
        // Check that default values are applied
        $this->assertEquals('updated default', $row['body']['varchar_with_default']);
        $this->assertEquals('Updated text default value', $row['body']['text_with_default']);
    }

    /**
     * @depends testCreateRowWithStringTypes
     */
    public function testCreateRowFailures(array $data): void
    {
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

    /**
     * @depends testCreateRowWithStringTypes
     */
    public function testGetVarcharColumn(array $data): void
    {
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

    /**
     * @depends testCreateRowWithStringTypes
     */
    public function testGetTextColumn(array $data): void
    {
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

    /**
     * @depends testCreateRowWithStringTypes
     */
    public function testGetMediumtextColumn(array $data): void
    {
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

    /**
     * @depends testCreateRowWithStringTypes
     */
    public function testGetLongtextColumn(array $data): void
    {
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

    /**
     * @depends testGetLongtextColumn
     */
    public function testGetTableWithStringTypeColumns(array $data): array
    {
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

        return $data;
    }

    /**
     * @depends testGetTableWithStringTypeColumns
     */
    public function testListColumnsWithStringTypes(array $data): array
    {
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

        return $data;
    }

    /**
     * @depends testListColumnsWithStringTypes
     */
    public function testDeleteStringTypeColumns(array $data): void
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Test SUCCESS: Delete varchar column
        $deleteVarchar = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_max', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(204, $deleteVarchar['headers']['status-code']);

        // Verify deletion
        $getDeleted = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/varchar_max', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(404, $getDeleted['headers']['status-code']);
    }
}
