<?php

namespace Tests\E2E\Services\Databases\Legacy;

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
    private static string $collectionId;

    public function testCreateDatabase(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
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
    public function testCreateCollection(array $data): array
    {
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'String Types Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        self::$collectionId = $collection['body']['$id'];

        return [
            'databaseId' => $data['databaseId'],
            'collectionId' => $collection['body']['$id'],
        ];
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateVarcharAttribute(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Create varchar attribute with valid size
        $varchar = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharWithDefault = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharRequired = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharMin = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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

        // Test SUCCESS: Create encrypted varchar attribute
        $varcharEncrypted = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
     * @depends testCreateCollection
     */
    public function testCreateVarcharAttributeFailures(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test FAILURE: Size 0
        $varcharZero = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharNegative = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharTooLarge = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharNoSize = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'varchar_no_size',
            'required' => false,
        ]);

        $this->assertEquals(400, $varcharNoSize['headers']['status-code']);

        // Test FAILURE: Default value exceeds size
        $varcharDefaultTooLong = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharDuplicate = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
        $varcharEncryptTooSmall = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', [
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
     * @depends testCreateCollection
     */
    public function testCreateTextAttribute(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Create text attribute
        $text = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', [
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
        $textWithDefault = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', [
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
        $textRequired = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', [
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
        $textArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', [
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

        // Test SUCCESS: Create encrypted text attribute
        $textEncrypted = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', [
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
     * @depends testCreateCollection
     */
    public function testCreateMediumtextAttribute(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Create mediumtext attribute
        $mediumtext = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', [
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
        $mediumtextWithDefault = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', [
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
        $mediumtextRequired = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', [
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
        $mediumtextArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', [
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

        // Test SUCCESS: Create encrypted mediumtext attribute
        $mediumtextEncrypted = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', [
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
     * @depends testCreateCollection
     */
    public function testCreateLongtextAttribute(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Create longtext attribute
        $longtext = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', [
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
        $longtextWithDefault = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', [
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
        $longtextRequired = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', [
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
        $longtextArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', [
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

        // Test SUCCESS: Create encrypted longtext attribute
        $longtextEncrypted = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', [
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
     * @depends testCreateLongtextAttribute
     */
    public function testListStringTypeAttributes(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Wait for attributes to be created
        sleep(2);

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $attributes = $response['body']['attributes'];
        $types = array_column($attributes, 'type');

        $this->assertContains('varchar', $types);
        $this->assertContains('text', $types);
        $this->assertContains('mediumtext', $types);
        $this->assertContains('longtext', $types);

        return $data;
    }

    /**
     * @depends testListStringTypeAttributes
     */
    public function testGetCollectionWithStringTypeAttributes(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $attributes = $response['body']['attributes'];
        $types = array_column($attributes, 'type');

        $this->assertContains('varchar', $types);
        $this->assertContains('text', $types);
        $this->assertContains('mediumtext', $types);
        $this->assertContains('longtext', $types);

        return $data;
    }

    /**
     * @depends testGetCollectionWithStringTypeAttributes
     */
    public function testUpdateVarcharAttribute(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports VARCHAR type');

        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Wait for attributes to be created
        sleep(3);

        // Test SUCCESS: Update varchar default value
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar/varchar_with_default', [
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
        $updateRequired = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar/varchar_field', [
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
        $updateKey = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar/varchar_min', [
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
     * @depends testUpdateVarcharAttribute
     */
    public function testUpdateTextAttribute(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports TEXT type');

        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Update text default value
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text/text_with_default', [
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
     * @depends testUpdateTextAttribute
     */
    public function testUpdateMediumtextAttribute(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports MEDIUMTEXT type');

        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Update mediumtext default value
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext/mediumtext_with_default', [
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
     * @depends testUpdateMediumtextAttribute
     */
    public function testUpdateLongtextAttribute(array $data): array
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports LONGTEXT type');

        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Update longtext default value
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext/longtext_with_default', [
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
     * @depends testUpdateLongtextAttribute
     */
    public function testCreateDocumentWithStringTypes(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Wait for all attributes to be available
        sleep(2);

        // Test SUCCESS: Create document with all string types
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
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

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals('Test varchar value', $document['body']['varchar_field']);
        $this->assertEquals('Required value', $document['body']['varchar_required']);
        $this->assertEquals('This is a text field with more content.', $document['body']['text_field']);
        $this->assertEquals('Required text', $document['body']['text_required']);
        $this->assertEquals('Medium text content here', $document['body']['mediumtext_field']);
        $this->assertEquals('Long text content for storing large amounts of data', $document['body']['longtext_field']);
        $this->assertCount(3, $document['body']['varchar_array']);
        $this->assertCount(2, $document['body']['text_array']);

        return array_merge($data, ['documentId' => $document['body']['$id']]);
    }

    /**
     * @depends testCreateDocumentWithStringTypes
     */
    public function testCreateDocumentWithDefaultValues(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Create document using default values
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
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

        $this->assertEquals(201, $document['headers']['status-code']);
        // Check that default values are applied
        $this->assertEquals('updated default', $document['body']['varchar_with_default']);
        $this->assertEquals('Updated text default value', $document['body']['text_with_default']);
    }

    /**
     * @depends testCreateDocumentWithStringTypes
     */
    public function testCreateDocumentFailures(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test FAILURE: Missing required field
        $docMissingRequired = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'varchar_field' => 'Value',
                // Missing varchar_required, text_required, etc.
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(400, $docMissingRequired['headers']['status-code']);
    }

    /**
     * @depends testCreateDocumentWithStringTypes
     */
    public function testGetVarcharAttribute(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('varchar_with_default', $attribute['body']['key']);
        $this->assertEquals('varchar', $attribute['body']['type']);
        $this->assertEquals(100, $attribute['body']['size']);
    }

    /**
     * @depends testCreateDocumentWithStringTypes
     */
    public function testGetTextAttribute(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('text_field', $attribute['body']['key']);
        $this->assertEquals('text', $attribute['body']['type']);
    }

    /**
     * @depends testCreateDocumentWithStringTypes
     */
    public function testGetMediumtextAttribute(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('mediumtext_field', $attribute['body']['key']);
        $this->assertEquals('mediumtext', $attribute['body']['type']);
    }

    /**
     * @depends testCreateDocumentWithStringTypes
     */
    public function testGetLongtextAttribute(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $attribute = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('longtext_field', $attribute['body']['key']);
        $this->assertEquals('longtext', $attribute['body']['type']);
    }

    /**
     * @depends testGetLongtextAttribute
     */
    public function testDeleteStringTypeAttributes(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Delete varchar attribute
        $deleteVarchar = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_max', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(204, $deleteVarchar['headers']['status-code']);

        // Verify deletion
        $getDeleted = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_max', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(404, $getDeleted['headers']['status-code']);
    }
}
