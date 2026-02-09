<?php

namespace Tests\E2E\Services\Databases\Legacy;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
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
    use ApiLegacy;
    use DatabasesUrlHelpers;
    use SchemaPolling;

    private static array $setupCache = [];

    /**
     * Setup database, collection, and all attributes for parallel-safe tests.
     */
    protected function setupDatabaseAndCollection(): array
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
        $database = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'String Types Test Database'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'String Types Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create varchar attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', $headers, [
            'key' => 'varchar_field', 'size' => 255, 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', $headers, [
            'key' => 'varchar_with_default', 'size' => 100, 'required' => false, 'default' => 'hello world',
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', $headers, [
            'key' => 'varchar_required', 'size' => 50, 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', $headers, [
            'key' => 'varchar_array', 'size' => 64, 'required' => false, 'array' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar', $headers, [
            'key' => 'varchar_min', 'size' => 1, 'required' => false,
        ]);

        // Create text attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', $headers, [
            'key' => 'text_field', 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', $headers, [
            'key' => 'text_with_default', 'required' => false, 'default' => 'This is a longer default text value that can contain more content.',
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', $headers, [
            'key' => 'text_required', 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text', $headers, [
            'key' => 'text_array', 'required' => false, 'array' => true,
        ]);

        // Create mediumtext attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', $headers, [
            'key' => 'mediumtext_field', 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', $headers, [
            'key' => 'mediumtext_with_default', 'required' => false, 'default' => 'Default mediumtext content',
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', $headers, [
            'key' => 'mediumtext_required', 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext', $headers, [
            'key' => 'mediumtext_array', 'required' => false, 'array' => true,
        ]);

        // Create longtext attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', $headers, [
            'key' => 'longtext_field', 'required' => false,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', $headers, [
            'key' => 'longtext_with_default', 'required' => false, 'default' => 'Default longtext content for very large text storage',
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', $headers, [
            'key' => 'longtext_required', 'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext', $headers, [
            'key' => 'longtext_array', 'required' => false, 'array' => true,
        ]);

        // Wait for all attributes to be available
        $this->waitForAllAttributes($databaseId, $collectionId);

        static::$setupCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
        ];

        return static::$setupCache[$cacheKey];
    }

    public function testCreateDatabase(): void
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
    }

    public function testCreateCollection(): void
    {
        $data = $this->setupDatabaseAndCollection();

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['collectionId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals($data['collectionId'], $collection['body']['$id']);
    }

    public function testCreateVarcharAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Verify varchar attributes were created correctly
        $varchar = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $varchar['headers']['status-code']);
        $this->assertEquals('varchar_field', $varchar['body']['key']);
        $this->assertEquals('varchar', $varchar['body']['type']);
        $this->assertEquals(255, $varchar['body']['size']);
        $this->assertEquals(false, $varchar['body']['required']);
        $this->assertNull($varchar['body']['default']);

        // Verify varchar with default
        $varcharWithDefault = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $varcharWithDefault['headers']['status-code']);
        $this->assertEquals('hello world', $varcharWithDefault['body']['default']);

        // Verify required varchar
        $varcharRequired = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $varcharRequired['headers']['status-code']);
        $this->assertEquals(true, $varcharRequired['body']['required']);

        // Verify array varchar
        $varcharArray = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $varcharArray['headers']['status-code']);
        $this->assertEquals(true, $varcharArray['body']['array']);

        // Verify min size varchar
        $varcharMin = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $varcharMin['headers']['status-code']);
        $this->assertEquals(1, $varcharMin['body']['size']);
    }

    public function testCreateVarcharAttributeFailures(): void
    {
        $data = $this->setupDatabaseAndCollection();
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
    }

    public function testCreateTextAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $text = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $text['headers']['status-code']);
        $this->assertEquals('text_field', $text['body']['key']);
        $this->assertEquals('text', $text['body']['type']);
        $this->assertEquals(false, $text['body']['required']);

        // Verify text with default
        $textWithDefault = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $textWithDefault['headers']['status-code']);
        $this->assertEquals('This is a longer default text value that can contain more content.', $textWithDefault['body']['default']);

        // Verify required text
        $textRequired = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $textRequired['headers']['status-code']);
        $this->assertEquals(true, $textRequired['body']['required']);

        // Verify text array
        $textArray = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/text_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $textArray['headers']['status-code']);
        $this->assertEquals(true, $textArray['body']['array']);
    }

    public function testCreateMediumtextAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $mediumtext = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $mediumtext['headers']['status-code']);
        $this->assertEquals('mediumtext_field', $mediumtext['body']['key']);
        $this->assertEquals('mediumtext', $mediumtext['body']['type']);
        $this->assertEquals(false, $mediumtext['body']['required']);

        // Verify mediumtext with default
        $mediumtextWithDefault = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $mediumtextWithDefault['headers']['status-code']);

        // Verify required mediumtext
        $mediumtextRequired = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $mediumtextRequired['headers']['status-code']);
        $this->assertEquals(true, $mediumtextRequired['body']['required']);

        // Verify mediumtext array
        $mediumtextArray = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/mediumtext_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $mediumtextArray['headers']['status-code']);
        $this->assertEquals(true, $mediumtextArray['body']['array']);
    }

    public function testCreateLongtextAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $longtext = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext_field', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $longtext['headers']['status-code']);
        $this->assertEquals('longtext_field', $longtext['body']['key']);
        $this->assertEquals('longtext', $longtext['body']['type']);
        $this->assertEquals(false, $longtext['body']['required']);

        // Verify longtext with default
        $longtextWithDefault = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext_with_default', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $longtextWithDefault['headers']['status-code']);

        // Verify required longtext
        $longtextRequired = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext_required', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $longtextRequired['headers']['status-code']);
        $this->assertEquals(true, $longtextRequired['body']['required']);

        // Verify longtext array
        $longtextArray = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/longtext_array', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $longtextArray['headers']['status-code']);
        $this->assertEquals(true, $longtextArray['body']['array']);
    }

    public function testUpdateVarcharAttribute(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports VARCHAR type');
    }

    public function testUpdateTextAttribute(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports TEXT type');
    }

    public function testUpdateMediumtextAttribute(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports MEDIUMTEXT type');
    }

    public function testUpdateLongtextAttribute(): void
    {
        $this->markTestSkipped('Skipped until utopia-php/database updateAttribute supports LONGTEXT type');
    }

    public function testCreateDocumentWithStringTypes(): void
    {
        $data = $this->setupDatabaseAndCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

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
    }

    public function testCreateDocumentWithDefaultValues(): void
    {
        $data = $this->setupDatabaseAndCollection();
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
        // Check that default values are applied (original defaults, update tests are skipped)
        $this->assertEquals('hello world', $document['body']['varchar_with_default']);
        $this->assertEquals('This is a longer default text value that can contain more content.', $document['body']['text_with_default']);
    }

    public function testCreateDocumentFailures(): void
    {
        $data = $this->setupDatabaseAndCollection();
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

    public function testGetVarcharAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
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

    public function testGetTextAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
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

    public function testGetMediumtextAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
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

    public function testGetLongtextAttribute(): void
    {
        $data = $this->setupDatabaseAndCollection();
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

    public function testDeleteStringTypeAttributes(): void
    {
        $data = $this->setupDatabaseAndCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Test SUCCESS: Delete varchar attribute
        $deleteVarchar = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(204, $deleteVarchar['headers']['status-code']);

        // Wait for async deletion to complete
        sleep(2);

        // Verify deletion
        $getDeleted = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/varchar_min', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(404, $getDeleted['headers']['status-code']);
    }
}
