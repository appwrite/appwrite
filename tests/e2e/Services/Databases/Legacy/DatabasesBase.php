<?php

namespace Tests\E2E\Services\Databases\Legacy;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait DatabasesBase
{
    public function testCreateDatabase(): array
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Test Database', $database['body']['name']);
        $this->assertEquals('legacy', $database['body']['type']);

        return ['databaseId' => $database['body']['$id']];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateCollection(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $actors['headers']['status-code']);
        $this->assertEquals($actors['body']['name'], 'Actors');

        return [
            'databaseId' => $databaseId,
            'moviesId' => $movies['body']['$id'],
            'actorsId' => $actors['body']['$id'],
        ];
    }

    /**
     * @depends testCreateCollection
     */
    public function testConsoleProject(array $data): void
    {
        if ($this->getSide() === 'server') {
            // Server side can't get past the invalid key check anyway
            $this->expectNotToPerformAssertions();
            return;
        }

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/console/collections/' . $data['moviesId'] . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => 'console',
            ], $this->getHeaders())
        );

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_access_forbidden', $response['body']['type']);
        $this->assertEquals('This endpoint is not available for the console project. The Appwrite Console is a reserved project ID and cannot be used with the Appwrite SDKs and APIs. Please check if your project ID is correct.', $response['body']['message']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/console/collections/' . $data['moviesId'] . '/documents',
            array_merge([
                'content-type' => 'application/json',
                // 'x-appwrite-project' => '', empty header
            ], $this->getHeaders())
        );
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('No Appwrite project was specified. Please specify your project ID when initializing your Appwrite SDK.', $response['body']['message']);
    }

    /**
     * @depends testCreateCollection
     */
    public function testDisableCollection(array $data): void
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Movies',
            'enabled' => false,
            'documentSecurity' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['enabled']);

        if ($this->getSide() === 'client') {
            $responseCreateDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documentId' => ID::unique(),
                'data' => [
                    'title' => 'Captain America',
                ],
                'permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ],
            ]);

            $this->assertEquals(404, $responseCreateDocument['headers']['status-code']);

            $responseListDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(404, $responseListDocument['headers']['status-code']);

            $responseGetDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/someID', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(404, $responseGetDocument['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Movies',
            'enabled' => true,
            'documentSecurity' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['enabled']);
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateAttributes(array $data): array
    {
        $databaseId = $data['databaseId'];

        $title = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $description = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $tagline = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'tagline',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $releaseYear = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYear',
            'required' => true,
            'min' => 1900,
            'max' => 2200,
        ]);

        $duration = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'duration',
            'required' => false,
            'min' => 60,
        ]);

        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $datetime = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'birthDay',
            'required' => false,
        ]);

        $relationship = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $data['actorsId'],
            'type' => 'oneToMany',
            'twoWay' => true,
            'key' => 'starringActors',
            'twoWayKey' => 'movie'
        ]);

        $integers = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'integers',
            'required' => false,
            'array' => true,
            'min' => 10,
            'max' => 99,
        ]);

        $this->assertEquals(202, $title['headers']['status-code']);
        $this->assertEquals($title['body']['key'], 'title');
        $this->assertEquals($title['body']['type'], 'string');
        $this->assertEquals($title['body']['size'], 256);
        $this->assertEquals($title['body']['required'], true);
        $this->assertFalse($title['body']['encrypt']);
        $this->assertEquals(202, $description['headers']['status-code']);
        $this->assertEquals($description['body']['key'], 'description');
        $this->assertEquals($description['body']['type'], 'string');
        $this->assertEquals($description['body']['size'], 512);
        $this->assertEquals($description['body']['required'], false);
        $this->assertEquals($description['body']['default'], '');

        $this->assertEquals(202, $tagline['headers']['status-code']);
        $this->assertEquals($tagline['body']['key'], 'tagline');
        $this->assertEquals($tagline['body']['type'], 'string');
        $this->assertEquals($tagline['body']['size'], 512);
        $this->assertEquals($tagline['body']['required'], false);
        $this->assertEquals($tagline['body']['default'], '');

        $this->assertEquals(202, $releaseYear['headers']['status-code']);
        $this->assertEquals($releaseYear['body']['key'], 'releaseYear');
        $this->assertEquals($releaseYear['body']['type'], 'integer');
        $this->assertEquals($releaseYear['body']['required'], true);

        $this->assertEquals(202, $duration['headers']['status-code']);
        $this->assertEquals($duration['body']['key'], 'duration');
        $this->assertEquals($duration['body']['type'], 'integer');
        $this->assertEquals($duration['body']['required'], false);

        $this->assertEquals(202, $actors['headers']['status-code']);
        $this->assertEquals($actors['body']['key'], 'actors');
        $this->assertEquals($actors['body']['type'], 'string');
        $this->assertEquals($actors['body']['size'], 256);
        $this->assertEquals($actors['body']['required'], false);
        $this->assertEquals($actors['body']['array'], true);

        $this->assertEquals($datetime['headers']['status-code'], 202);
        $this->assertEquals($datetime['body']['key'], 'birthDay');
        $this->assertEquals($datetime['body']['type'], 'datetime');
        $this->assertEquals($datetime['body']['required'], false);

        $this->assertEquals($relationship['headers']['status-code'], 202);
        $this->assertEquals($relationship['body']['key'], 'starringActors');
        $this->assertEquals($relationship['body']['type'], 'relationship');
        $this->assertEquals($relationship['body']['relatedCollection'], $data['actorsId']);
        $this->assertEquals($relationship['body']['relationType'], 'oneToMany');
        $this->assertEquals($relationship['body']['twoWay'], true);
        $this->assertEquals($relationship['body']['twoWayKey'], 'movie');

        $this->assertEquals(202, $integers['headers']['status-code']);
        $this->assertEquals($integers['body']['key'], 'integers');
        $this->assertEquals($integers['body']['type'], 'integer');
        $this->assertArrayNotHasKey('size', $integers['body']);
        $this->assertEquals($integers['body']['required'], false);
        $this->assertEquals($integers['body']['array'], true);

        // wait for database worker to create attributes
        sleep(2);

        $movies = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertIsArray($movies['body']['attributes']);
        $this->assertCount(9, $movies['body']['attributes']);
        $this->assertEquals($movies['body']['attributes'][0]['key'], $title['body']['key']);
        $this->assertEquals($movies['body']['attributes'][1]['key'], $description['body']['key']);
        $this->assertEquals($movies['body']['attributes'][2]['key'], $tagline['body']['key']);
        $this->assertEquals($movies['body']['attributes'][3]['key'], $releaseYear['body']['key']);
        $this->assertEquals($movies['body']['attributes'][4]['key'], $duration['body']['key']);
        $this->assertEquals($movies['body']['attributes'][5]['key'], $actors['body']['key']);
        $this->assertEquals($movies['body']['attributes'][6]['key'], $datetime['body']['key']);
        $this->assertEquals($movies['body']['attributes'][7]['key'], $relationship['body']['key']);
        $this->assertEquals($movies['body']['attributes'][8]['key'], $integers['body']['key']);

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testListAttributes(array $data): void
    {
        $databaseId = $data['databaseId'];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'queries' => [
                Query::equal('type', ['string'])->toString(),
                Query::limit(2)->toString(),
                Query::cursorAfter(new Document(['$id' => 'title']))->toString()
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, \count($response['body']['attributes']));
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'queries' => [Query::select(['key'])->toString()],
        ]);
        $this->assertEquals(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);
        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateDatabase
     */
    public function testPatchAttribute(array $data): void
    {
        $databaseId = $data['databaseId'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'patch',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'patch');

        $attribute = $this->client->call(Client::METHOD_POST, '/databases/'.$databaseId.'/collections/'.$collection['body']['$id'].'/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'required' => true,
            'size' => 100,
        ]);
        $this->assertEquals(202, $attribute['headers']['status-code']);
        $this->assertEquals($attribute['body']['size'], 100);

        sleep(1);

        $index = $this->client->call(Client::METHOD_POST, '/databases/'.$databaseId.'/collections/'.$collection['body']['$id'].'/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'titleIndex',
            'type' => 'key',
            'attributes' => ['title'],
        ]);
        $this->assertEquals(202, $index['headers']['status-code']);

        sleep(1);

        /**
         * Update attribute size to exceed Index maximum length
         */
        $attribute = $this->client->call(Client::METHOD_PATCH, '/databases/'.$databaseId.'/collections/'.$collection['body']['$id'].'/attributes/string/'.$attribute['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'size' => 1000,
            'required' => true,
            'default' => null,
        ]);

        $this->assertEquals(400, $attribute['headers']['status-code']);
        $this->assertStringContainsString('Index length is longer than the maximum: 76', $attribute['body']['message']);
    }

    public function testUpdateAttributeEnum(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database 2'
        ]);

        $players = $this->client->call(Client::METHOD_POST, '/databases/' . $database['body']['$id'] . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Players',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create enum attribute
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $database['body']['$id'] . '/collections/' . $players['body']['$id'] . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'position',
            'elements' => ['goalkeeper', 'defender', 'midfielder', 'forward'],
            'required' => true,
            'array' => false,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);
        $this->assertEquals($attribute['body']['key'], 'position');
        $this->assertEquals($attribute['body']['elements'], ['goalkeeper', 'defender', 'midfielder', 'forward']);

        \sleep(2);

        // Update enum attribute
        $attribute = $this->client->call(Client::METHOD_PATCH, '/databases/' . $database['body']['$id'] . '/collections/' . $players['body']['$id'] . '/attributes/enum/' . $attribute['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'elements' => ['goalkeeper', 'defender', 'midfielder', 'forward', 'coach'],
            'required' => true,
            'default' => null
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals($attribute['body']['elements'], ['goalkeeper', 'defender', 'midfielder', 'forward', 'coach']);
    }

    /**
     * @depends testCreateAttributes
     */
    public function testAttributeResponseModels(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Response Models',
            // 'permissions' missing on purpose to make sure it's optional
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'Response Models');

        $collectionId = $collection['body']['$id'];

        $attributesPath = "/databases/" . $databaseId . "/collections/{$collectionId}/attributes";

        $string = $this->client->call(Client::METHOD_POST, $attributesPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 16,
            'required' => false,
            'default' => 'default',
        ]);

        $email = $this->client->call(Client::METHOD_POST, $attributesPath . '/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false,
            'default' => 'default@example.com',
        ]);

        $enum = $this->client->call(Client::METHOD_POST, $attributesPath . '/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', 'maybe'],
            'required' => false,
            'default' => 'maybe',
        ]);

        $ip = $this->client->call(Client::METHOD_POST, $attributesPath . '/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false,
            'default' => '192.0.2.0',
        ]);

        $url = $this->client->call(Client::METHOD_POST, $attributesPath . '/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'required' => false,
            'default' => 'http://example.com',
        ]);

        $integer = $this->client->call(Client::METHOD_POST, $attributesPath . '/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'integer',
            'required' => false,
            'min' => 1,
            'max' => 5,
            'default' => 3
        ]);

        $float = $this->client->call(Client::METHOD_POST, $attributesPath . '/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'float',
            'required' => false,
            'min' => 1.5,
            'max' => 5.5,
            'default' => 3.5
        ]);

        $boolean = $this->client->call(Client::METHOD_POST, $attributesPath . '/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boolean',
            'required' => false,
            'default' => true,
        ]);

        $datetime = $this->client->call(Client::METHOD_POST, $attributesPath . '/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'datetime',
            'required' => false,
            'default' => null,
        ]);

        $relationship = $this->client->call(Client::METHOD_POST, $attributesPath . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $data['actorsId'],
            'type' => 'oneToMany',
            'twoWay' => true,
            'key' => 'relationship',
            'twoWayKey' => 'twoWayKey'
        ]);

        $strings = $this->client->call(Client::METHOD_POST, $attributesPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'names',
            'size' => 512,
            'required' => false,
            'array' => true,
        ]);

        $integers = $this->client->call(Client::METHOD_POST, $attributesPath . '/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'numbers',
            'required' => false,
            'array' => true,
            'min' => 1,
            'max' => 999,
        ]);

        $this->assertEquals(202, $string['headers']['status-code']);
        $this->assertEquals('string', $string['body']['key']);
        $this->assertEquals('string', $string['body']['type']);
        $this->assertEquals(false, $string['body']['required']);
        $this->assertEquals(false, $string['body']['array']);
        $this->assertEquals(16, $string['body']['size']);
        $this->assertEquals('default', $string['body']['default']);

        $this->assertEquals(202, $email['headers']['status-code']);
        $this->assertEquals('email', $email['body']['key']);
        $this->assertEquals('string', $email['body']['type']);
        $this->assertEquals(false, $email['body']['required']);
        $this->assertEquals(false, $email['body']['array']);
        $this->assertEquals('email', $email['body']['format']);
        $this->assertEquals('default@example.com', $email['body']['default']);

        $this->assertEquals(202, $enum['headers']['status-code']);
        $this->assertEquals('enum', $enum['body']['key']);
        $this->assertEquals('string', $enum['body']['type']);
        $this->assertEquals(false, $enum['body']['required']);
        $this->assertEquals(false, $enum['body']['array']);
        $this->assertEquals('enum', $enum['body']['format']);
        $this->assertEquals('maybe', $enum['body']['default']);
        $this->assertIsArray($enum['body']['elements']);
        $this->assertEquals(['yes', 'no', 'maybe'], $enum['body']['elements']);

        $this->assertEquals(202, $ip['headers']['status-code']);
        $this->assertEquals('ip', $ip['body']['key']);
        $this->assertEquals('string', $ip['body']['type']);
        $this->assertEquals(false, $ip['body']['required']);
        $this->assertEquals(false, $ip['body']['array']);
        $this->assertEquals('ip', $ip['body']['format']);
        $this->assertEquals('192.0.2.0', $ip['body']['default']);

        $this->assertEquals(202, $url['headers']['status-code']);
        $this->assertEquals('url', $url['body']['key']);
        $this->assertEquals('string', $url['body']['type']);
        $this->assertEquals(false, $url['body']['required']);
        $this->assertEquals(false, $url['body']['array']);
        $this->assertEquals('url', $url['body']['format']);
        $this->assertEquals('http://example.com', $url['body']['default']);

        $this->assertEquals(202, $integer['headers']['status-code']);
        $this->assertEquals('integer', $integer['body']['key']);
        $this->assertEquals('integer', $integer['body']['type']);
        $this->assertEquals(false, $integer['body']['required']);
        $this->assertEquals(false, $integer['body']['array']);
        $this->assertEquals(1, $integer['body']['min']);
        $this->assertEquals(5, $integer['body']['max']);
        $this->assertEquals(3, $integer['body']['default']);

        $this->assertEquals(202, $float['headers']['status-code']);
        $this->assertEquals('float', $float['body']['key']);
        $this->assertEquals('double', $float['body']['type']);
        $this->assertEquals(false, $float['body']['required']);
        $this->assertEquals(false, $float['body']['array']);
        $this->assertEquals(1.5, $float['body']['min']);
        $this->assertEquals(5.5, $float['body']['max']);
        $this->assertEquals(3.5, $float['body']['default']);

        $this->assertEquals(202, $boolean['headers']['status-code']);
        $this->assertEquals('boolean', $boolean['body']['key']);
        $this->assertEquals('boolean', $boolean['body']['type']);
        $this->assertEquals(false, $boolean['body']['required']);
        $this->assertEquals(false, $boolean['body']['array']);
        $this->assertEquals(true, $boolean['body']['default']);

        $this->assertEquals(202, $datetime['headers']['status-code']);
        $this->assertEquals('datetime', $datetime['body']['key']);
        $this->assertEquals('datetime', $datetime['body']['type']);
        $this->assertEquals(false, $datetime['body']['required']);
        $this->assertEquals(false, $datetime['body']['array']);
        $this->assertEquals(null, $datetime['body']['default']);

        $this->assertEquals(202, $relationship['headers']['status-code']);
        $this->assertEquals('relationship', $relationship['body']['key']);
        $this->assertEquals('relationship', $relationship['body']['type']);
        $this->assertEquals(false, $relationship['body']['required']);
        $this->assertEquals(false, $relationship['body']['array']);
        $this->assertEquals($data['actorsId'], $relationship['body']['relatedCollection']);
        $this->assertEquals('oneToMany', $relationship['body']['relationType']);
        $this->assertEquals(true, $relationship['body']['twoWay']);
        $this->assertEquals('twoWayKey', $relationship['body']['twoWayKey']);

        $this->assertEquals(202, $strings['headers']['status-code']);
        $this->assertEquals('names', $strings['body']['key']);
        $this->assertEquals('string', $strings['body']['type']);
        $this->assertEquals(false, $strings['body']['required']);
        $this->assertEquals(true, $strings['body']['array']);
        $this->assertEquals(null, $strings['body']['default']);

        $this->assertEquals(202, $integers['headers']['status-code']);
        $this->assertEquals('numbers', $integers['body']['key']);
        $this->assertEquals('integer', $integers['body']['type']);
        $this->assertEquals(false, $integers['body']['required']);
        $this->assertEquals(true, $integers['body']['array']);
        $this->assertEquals(1, $integers['body']['min']);
        $this->assertEquals(999, $integers['body']['max']);
        $this->assertEquals(null, $integers['body']['default']);

        // Wait for database worker to create attributes
        sleep(5);

        $stringResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $string['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $emailResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $email['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $enumResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $enum['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $ipResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $ip['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $urlResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $url['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $integerResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $integer['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $floatResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $float['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $booleanResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $boolean['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $datetimeResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $datetime['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $relationshipResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $relationship['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $stringsResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $strings['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $integersResponse = $this->client->call(Client::METHOD_GET, $attributesPath . '/' . $integers['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $stringResponse['headers']['status-code']);
        $this->assertEquals($string['body']['key'], $stringResponse['body']['key']);
        $this->assertEquals($string['body']['type'], $stringResponse['body']['type']);
        $this->assertEquals('available', $stringResponse['body']['status']);
        $this->assertEquals($string['body']['required'], $stringResponse['body']['required']);
        $this->assertEquals($string['body']['array'], $stringResponse['body']['array']);
        $this->assertEquals(16, $stringResponse['body']['size']);
        $this->assertEquals($string['body']['default'], $stringResponse['body']['default']);

        $this->assertEquals(200, $emailResponse['headers']['status-code']);
        $this->assertEquals($email['body']['key'], $emailResponse['body']['key']);
        $this->assertEquals($email['body']['type'], $emailResponse['body']['type']);
        $this->assertEquals('available', $emailResponse['body']['status']);
        $this->assertEquals($email['body']['required'], $emailResponse['body']['required']);
        $this->assertEquals($email['body']['array'], $emailResponse['body']['array']);
        $this->assertEquals($email['body']['format'], $emailResponse['body']['format']);
        $this->assertEquals($email['body']['default'], $emailResponse['body']['default']);

        $this->assertEquals(200, $enumResponse['headers']['status-code']);
        $this->assertEquals($enum['body']['key'], $enumResponse['body']['key']);
        $this->assertEquals($enum['body']['type'], $enumResponse['body']['type']);
        $this->assertEquals('available', $enumResponse['body']['status']);
        $this->assertEquals($enum['body']['required'], $enumResponse['body']['required']);
        $this->assertEquals($enum['body']['array'], $enumResponse['body']['array']);
        $this->assertEquals($enum['body']['format'], $enumResponse['body']['format']);
        $this->assertEquals($enum['body']['default'], $enumResponse['body']['default']);
        $this->assertEquals($enum['body']['elements'], $enumResponse['body']['elements']);

        $this->assertEquals(200, $ipResponse['headers']['status-code']);
        $this->assertEquals($ip['body']['key'], $ipResponse['body']['key']);
        $this->assertEquals($ip['body']['type'], $ipResponse['body']['type']);
        $this->assertEquals('available', $ipResponse['body']['status']);
        $this->assertEquals($ip['body']['required'], $ipResponse['body']['required']);
        $this->assertEquals($ip['body']['array'], $ipResponse['body']['array']);
        $this->assertEquals($ip['body']['format'], $ipResponse['body']['format']);
        $this->assertEquals($ip['body']['default'], $ipResponse['body']['default']);

        $this->assertEquals(200, $urlResponse['headers']['status-code']);
        $this->assertEquals($url['body']['key'], $urlResponse['body']['key']);
        $this->assertEquals($url['body']['type'], $urlResponse['body']['type']);
        $this->assertEquals('available', $urlResponse['body']['status']);
        $this->assertEquals($url['body']['required'], $urlResponse['body']['required']);
        $this->assertEquals($url['body']['array'], $urlResponse['body']['array']);
        $this->assertEquals($url['body']['format'], $urlResponse['body']['format']);
        $this->assertEquals($url['body']['default'], $urlResponse['body']['default']);

        $this->assertEquals(200, $integerResponse['headers']['status-code']);
        $this->assertEquals($integer['body']['key'], $integerResponse['body']['key']);
        $this->assertEquals($integer['body']['type'], $integerResponse['body']['type']);
        $this->assertEquals('available', $integerResponse['body']['status']);
        $this->assertEquals($integer['body']['required'], $integerResponse['body']['required']);
        $this->assertEquals($integer['body']['array'], $integerResponse['body']['array']);
        $this->assertEquals($integer['body']['min'], $integerResponse['body']['min']);
        $this->assertEquals($integer['body']['max'], $integerResponse['body']['max']);
        $this->assertEquals($integer['body']['default'], $integerResponse['body']['default']);

        $this->assertEquals(200, $floatResponse['headers']['status-code']);
        $this->assertEquals($float['body']['key'], $floatResponse['body']['key']);
        $this->assertEquals($float['body']['type'], $floatResponse['body']['type']);
        $this->assertEquals('available', $floatResponse['body']['status']);
        $this->assertEquals($float['body']['required'], $floatResponse['body']['required']);
        $this->assertEquals($float['body']['array'], $floatResponse['body']['array']);
        $this->assertEquals($float['body']['min'], $floatResponse['body']['min']);
        $this->assertEquals($float['body']['max'], $floatResponse['body']['max']);
        $this->assertEquals($float['body']['default'], $floatResponse['body']['default']);

        $this->assertEquals(200, $booleanResponse['headers']['status-code']);
        $this->assertEquals($boolean['body']['key'], $booleanResponse['body']['key']);
        $this->assertEquals($boolean['body']['type'], $booleanResponse['body']['type']);
        $this->assertEquals('available', $booleanResponse['body']['status']);
        $this->assertEquals($boolean['body']['required'], $booleanResponse['body']['required']);
        $this->assertEquals($boolean['body']['array'], $booleanResponse['body']['array']);
        $this->assertEquals($boolean['body']['default'], $booleanResponse['body']['default']);

        $this->assertEquals(200, $datetimeResponse['headers']['status-code']);
        $this->assertEquals($datetime['body']['key'], $datetimeResponse['body']['key']);
        $this->assertEquals($datetime['body']['type'], $datetimeResponse['body']['type']);
        $this->assertEquals('available', $datetimeResponse['body']['status']);
        $this->assertEquals($datetime['body']['required'], $datetimeResponse['body']['required']);
        $this->assertEquals($datetime['body']['array'], $datetimeResponse['body']['array']);
        $this->assertEquals($datetime['body']['default'], $datetimeResponse['body']['default']);

        $this->assertEquals(200, $relationshipResponse['headers']['status-code']);
        $this->assertEquals($relationship['body']['key'], $relationshipResponse['body']['key']);
        $this->assertEquals($relationship['body']['type'], $relationshipResponse['body']['type']);
        $this->assertEquals('available', $relationshipResponse['body']['status']);
        $this->assertEquals($relationship['body']['required'], $relationshipResponse['body']['required']);
        $this->assertEquals($relationship['body']['array'], $relationshipResponse['body']['array']);
        $this->assertEquals($relationship['body']['relatedCollection'], $relationshipResponse['body']['relatedCollection']);
        $this->assertEquals($relationship['body']['relationType'], $relationshipResponse['body']['relationType']);
        $this->assertEquals($relationship['body']['twoWay'], $relationshipResponse['body']['twoWay']);
        $this->assertEquals($relationship['body']['twoWayKey'], $relationshipResponse['body']['twoWayKey']);

        $attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attributes['headers']['status-code']);
        $this->assertEquals(12, $attributes['body']['total']);

        /**
         * Test for SUCCESS with total=false
         */
        $attributesWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'total' => false
        ]);

        $this->assertEquals(200, $attributesWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($attributesWithIncludeTotalFalse['body']);
        $this->assertIsArray($attributesWithIncludeTotalFalse['body']['attributes']);
        $this->assertIsInt($attributesWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $attributesWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($attributesWithIncludeTotalFalse['body']['attributes']));

        $attributes = $attributes['body']['attributes'];
        $this->assertIsArray($attributes);
        $this->assertCount(12, $attributes);

        $this->assertEquals($stringResponse['body']['key'], $attributes[0]['key']);
        $this->assertEquals($stringResponse['body']['type'], $attributes[0]['type']);
        $this->assertEquals($stringResponse['body']['status'], $attributes[0]['status']);
        $this->assertEquals($stringResponse['body']['required'], $attributes[0]['required']);
        $this->assertEquals($stringResponse['body']['array'], $attributes[0]['array']);
        $this->assertEquals($stringResponse['body']['size'], $attributes[0]['size']);
        $this->assertEquals($stringResponse['body']['default'], $attributes[0]['default']);

        $this->assertEquals($emailResponse['body']['key'], $attributes[1]['key']);
        $this->assertEquals($emailResponse['body']['type'], $attributes[1]['type']);
        $this->assertEquals($emailResponse['body']['status'], $attributes[1]['status']);
        $this->assertEquals($emailResponse['body']['required'], $attributes[1]['required']);
        $this->assertEquals($emailResponse['body']['array'], $attributes[1]['array']);
        $this->assertEquals($emailResponse['body']['default'], $attributes[1]['default']);
        $this->assertEquals($emailResponse['body']['format'], $attributes[1]['format']);

        $this->assertEquals($enumResponse['body']['key'], $attributes[2]['key']);
        $this->assertEquals($enumResponse['body']['type'], $attributes[2]['type']);
        $this->assertEquals($enumResponse['body']['status'], $attributes[2]['status']);
        $this->assertEquals($enumResponse['body']['required'], $attributes[2]['required']);
        $this->assertEquals($enumResponse['body']['array'], $attributes[2]['array']);
        $this->assertEquals($enumResponse['body']['default'], $attributes[2]['default']);
        $this->assertEquals($enumResponse['body']['format'], $attributes[2]['format']);
        $this->assertEquals($enumResponse['body']['elements'], $attributes[2]['elements']);

        $this->assertEquals($ipResponse['body']['key'], $attributes[3]['key']);
        $this->assertEquals($ipResponse['body']['type'], $attributes[3]['type']);
        $this->assertEquals($ipResponse['body']['status'], $attributes[3]['status']);
        $this->assertEquals($ipResponse['body']['required'], $attributes[3]['required']);
        $this->assertEquals($ipResponse['body']['array'], $attributes[3]['array']);
        $this->assertEquals($ipResponse['body']['default'], $attributes[3]['default']);
        $this->assertEquals($ipResponse['body']['format'], $attributes[3]['format']);

        $this->assertEquals($urlResponse['body']['key'], $attributes[4]['key']);
        $this->assertEquals($urlResponse['body']['type'], $attributes[4]['type']);
        $this->assertEquals($urlResponse['body']['status'], $attributes[4]['status']);
        $this->assertEquals($urlResponse['body']['required'], $attributes[4]['required']);
        $this->assertEquals($urlResponse['body']['array'], $attributes[4]['array']);
        $this->assertEquals($urlResponse['body']['default'], $attributes[4]['default']);
        $this->assertEquals($urlResponse['body']['format'], $attributes[4]['format']);

        $this->assertEquals($integerResponse['body']['key'], $attributes[5]['key']);
        $this->assertEquals($integerResponse['body']['type'], $attributes[5]['type']);
        $this->assertEquals($integerResponse['body']['status'], $attributes[5]['status']);
        $this->assertEquals($integerResponse['body']['required'], $attributes[5]['required']);
        $this->assertEquals($integerResponse['body']['array'], $attributes[5]['array']);
        $this->assertEquals($integerResponse['body']['default'], $attributes[5]['default']);
        $this->assertEquals($integerResponse['body']['min'], $attributes[5]['min']);
        $this->assertEquals($integerResponse['body']['max'], $attributes[5]['max']);

        $this->assertEquals($floatResponse['body']['key'], $attributes[6]['key']);
        $this->assertEquals($floatResponse['body']['type'], $attributes[6]['type']);
        $this->assertEquals($floatResponse['body']['status'], $attributes[6]['status']);
        $this->assertEquals($floatResponse['body']['required'], $attributes[6]['required']);
        $this->assertEquals($floatResponse['body']['array'], $attributes[6]['array']);
        $this->assertEquals($floatResponse['body']['default'], $attributes[6]['default']);
        $this->assertEquals($floatResponse['body']['min'], $attributes[6]['min']);
        $this->assertEquals($floatResponse['body']['max'], $attributes[6]['max']);

        $this->assertEquals($booleanResponse['body']['key'], $attributes[7]['key']);
        $this->assertEquals($booleanResponse['body']['type'], $attributes[7]['type']);
        $this->assertEquals($booleanResponse['body']['status'], $attributes[7]['status']);
        $this->assertEquals($booleanResponse['body']['required'], $attributes[7]['required']);
        $this->assertEquals($booleanResponse['body']['array'], $attributes[7]['array']);
        $this->assertEquals($booleanResponse['body']['default'], $attributes[7]['default']);

        $this->assertEquals($datetimeResponse['body']['key'], $attributes[8]['key']);
        $this->assertEquals($datetimeResponse['body']['type'], $attributes[8]['type']);
        $this->assertEquals($datetimeResponse['body']['status'], $attributes[8]['status']);
        $this->assertEquals($datetimeResponse['body']['required'], $attributes[8]['required']);
        $this->assertEquals($datetimeResponse['body']['array'], $attributes[8]['array']);
        $this->assertEquals($datetimeResponse['body']['default'], $attributes[8]['default']);

        $this->assertEquals($relationshipResponse['body']['key'], $attributes[9]['key']);
        $this->assertEquals($relationshipResponse['body']['type'], $attributes[9]['type']);
        $this->assertEquals($relationshipResponse['body']['status'], $attributes[9]['status']);
        $this->assertEquals($relationshipResponse['body']['required'], $attributes[9]['required']);
        $this->assertEquals($relationshipResponse['body']['array'], $attributes[9]['array']);
        $this->assertEquals($relationshipResponse['body']['relatedCollection'], $attributes[9]['relatedCollection']);
        $this->assertEquals($relationshipResponse['body']['relationType'], $attributes[9]['relationType']);
        $this->assertEquals($relationshipResponse['body']['twoWay'], $attributes[9]['twoWay']);
        $this->assertEquals($relationshipResponse['body']['twoWayKey'], $attributes[9]['twoWayKey']);

        $this->assertEquals($stringsResponse['body']['key'], $attributes[10]['key']);
        $this->assertEquals($stringsResponse['body']['type'], $attributes[10]['type']);
        $this->assertEquals($stringsResponse['body']['status'], $attributes[10]['status']);
        $this->assertEquals($stringsResponse['body']['required'], $attributes[10]['required']);
        $this->assertEquals($stringsResponse['body']['array'], $attributes[10]['array']);
        $this->assertEquals($stringsResponse['body']['default'], $attributes[10]['default']);

        $this->assertEquals($integersResponse['body']['key'], $attributes[11]['key']);
        $this->assertEquals($integersResponse['body']['type'], $attributes[11]['type']);
        $this->assertEquals($integersResponse['body']['status'], $attributes[11]['status']);
        $this->assertEquals($integersResponse['body']['required'], $attributes[11]['required']);
        $this->assertEquals($integersResponse['body']['array'], $attributes[11]['array']);
        $this->assertEquals($integersResponse['body']['default'], $attributes[11]['default']);
        $this->assertEquals($integersResponse['body']['min'], $attributes[11]['min']);
        $this->assertEquals($integersResponse['body']['max'], $attributes[11]['max']);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);

        $attributes = $collection['body']['attributes'];

        $this->assertIsArray($attributes);
        $this->assertCount(12, $attributes);

        $this->assertEquals($stringResponse['body']['key'], $attributes[0]['key']);
        $this->assertEquals($stringResponse['body']['type'], $attributes[0]['type']);
        $this->assertEquals($stringResponse['body']['status'], $attributes[0]['status']);
        $this->assertEquals($stringResponse['body']['required'], $attributes[0]['required']);
        $this->assertEquals($stringResponse['body']['array'], $attributes[0]['array']);
        $this->assertEquals($stringResponse['body']['size'], $attributes[0]['size']);
        $this->assertEquals($stringResponse['body']['default'], $attributes[0]['default']);

        $this->assertEquals($emailResponse['body']['key'], $attributes[1]['key']);
        $this->assertEquals($emailResponse['body']['type'], $attributes[1]['type']);
        $this->assertEquals($emailResponse['body']['status'], $attributes[1]['status']);
        $this->assertEquals($emailResponse['body']['required'], $attributes[1]['required']);
        $this->assertEquals($emailResponse['body']['array'], $attributes[1]['array']);
        $this->assertEquals($emailResponse['body']['default'], $attributes[1]['default']);
        $this->assertEquals($emailResponse['body']['format'], $attributes[1]['format']);

        $this->assertEquals($enumResponse['body']['key'], $attributes[2]['key']);
        $this->assertEquals($enumResponse['body']['type'], $attributes[2]['type']);
        $this->assertEquals($enumResponse['body']['status'], $attributes[2]['status']);
        $this->assertEquals($enumResponse['body']['required'], $attributes[2]['required']);
        $this->assertEquals($enumResponse['body']['array'], $attributes[2]['array']);
        $this->assertEquals($enumResponse['body']['default'], $attributes[2]['default']);
        $this->assertEquals($enumResponse['body']['format'], $attributes[2]['format']);
        $this->assertEquals($enumResponse['body']['elements'], $attributes[2]['elements']);

        $this->assertEquals($ipResponse['body']['key'], $attributes[3]['key']);
        $this->assertEquals($ipResponse['body']['type'], $attributes[3]['type']);
        $this->assertEquals($ipResponse['body']['status'], $attributes[3]['status']);
        $this->assertEquals($ipResponse['body']['required'], $attributes[3]['required']);
        $this->assertEquals($ipResponse['body']['array'], $attributes[3]['array']);
        $this->assertEquals($ipResponse['body']['default'], $attributes[3]['default']);
        $this->assertEquals($ipResponse['body']['format'], $attributes[3]['format']);

        $this->assertEquals($urlResponse['body']['key'], $attributes[4]['key']);
        $this->assertEquals($urlResponse['body']['type'], $attributes[4]['type']);
        $this->assertEquals($urlResponse['body']['status'], $attributes[4]['status']);
        $this->assertEquals($urlResponse['body']['required'], $attributes[4]['required']);
        $this->assertEquals($urlResponse['body']['array'], $attributes[4]['array']);
        $this->assertEquals($urlResponse['body']['default'], $attributes[4]['default']);
        $this->assertEquals($urlResponse['body']['format'], $attributes[4]['format']);

        $this->assertEquals($integerResponse['body']['key'], $attributes[5]['key']);
        $this->assertEquals($integerResponse['body']['type'], $attributes[5]['type']);
        $this->assertEquals($integerResponse['body']['status'], $attributes[5]['status']);
        $this->assertEquals($integerResponse['body']['required'], $attributes[5]['required']);
        $this->assertEquals($integerResponse['body']['array'], $attributes[5]['array']);
        $this->assertEquals($integerResponse['body']['default'], $attributes[5]['default']);
        $this->assertEquals($integerResponse['body']['min'], $attributes[5]['min']);
        $this->assertEquals($integerResponse['body']['max'], $attributes[5]['max']);

        $this->assertEquals($floatResponse['body']['key'], $attributes[6]['key']);
        $this->assertEquals($floatResponse['body']['type'], $attributes[6]['type']);
        $this->assertEquals($floatResponse['body']['status'], $attributes[6]['status']);
        $this->assertEquals($floatResponse['body']['required'], $attributes[6]['required']);
        $this->assertEquals($floatResponse['body']['array'], $attributes[6]['array']);
        $this->assertEquals($floatResponse['body']['default'], $attributes[6]['default']);
        $this->assertEquals($floatResponse['body']['min'], $attributes[6]['min']);
        $this->assertEquals($floatResponse['body']['max'], $attributes[6]['max']);

        $this->assertEquals($booleanResponse['body']['key'], $attributes[7]['key']);
        $this->assertEquals($booleanResponse['body']['type'], $attributes[7]['type']);
        $this->assertEquals($booleanResponse['body']['status'], $attributes[7]['status']);
        $this->assertEquals($booleanResponse['body']['required'], $attributes[7]['required']);
        $this->assertEquals($booleanResponse['body']['array'], $attributes[7]['array']);
        $this->assertEquals($booleanResponse['body']['default'], $attributes[7]['default']);

        $this->assertEquals($datetimeResponse['body']['key'], $attributes[8]['key']);
        $this->assertEquals($datetimeResponse['body']['type'], $attributes[8]['type']);
        $this->assertEquals($datetimeResponse['body']['status'], $attributes[8]['status']);
        $this->assertEquals($datetimeResponse['body']['required'], $attributes[8]['required']);
        $this->assertEquals($datetimeResponse['body']['array'], $attributes[8]['array']);
        $this->assertEquals($datetimeResponse['body']['default'], $attributes[8]['default']);

        $this->assertEquals($relationshipResponse['body']['key'], $attributes[9]['key']);
        $this->assertEquals($relationshipResponse['body']['type'], $attributes[9]['type']);
        $this->assertEquals($relationshipResponse['body']['status'], $attributes[9]['status']);
        $this->assertEquals($relationshipResponse['body']['required'], $attributes[9]['required']);
        $this->assertEquals($relationshipResponse['body']['array'], $attributes[9]['array']);
        $this->assertEquals($relationshipResponse['body']['relatedCollection'], $attributes[9]['relatedCollection']);
        $this->assertEquals($relationshipResponse['body']['relationType'], $attributes[9]['relationType']);
        $this->assertEquals($relationshipResponse['body']['twoWay'], $attributes[9]['twoWay']);
        $this->assertEquals($relationshipResponse['body']['twoWayKey'], $attributes[9]['twoWayKey']);

        $this->assertEquals($stringsResponse['body']['key'], $attributes[10]['key']);
        $this->assertEquals($stringsResponse['body']['type'], $attributes[10]['type']);
        $this->assertEquals($stringsResponse['body']['status'], $attributes[10]['status']);
        $this->assertEquals($stringsResponse['body']['required'], $attributes[10]['required']);
        $this->assertEquals($stringsResponse['body']['array'], $attributes[10]['array']);
        $this->assertEquals($stringsResponse['body']['default'], $attributes[10]['default']);

        $this->assertEquals($integersResponse['body']['key'], $attributes[11]['key']);
        $this->assertEquals($integersResponse['body']['type'], $attributes[11]['type']);
        $this->assertEquals($integersResponse['body']['status'], $attributes[11]['status']);
        $this->assertEquals($integersResponse['body']['required'], $attributes[11]['required']);
        $this->assertEquals($integersResponse['body']['array'], $attributes[11]['array']);
        $this->assertEquals($integersResponse['body']['default'], $attributes[11]['default']);
        $this->assertEquals($integersResponse['body']['min'], $attributes[11]['min']);
        $this->assertEquals($integersResponse['body']['max'], $attributes[11]['max']);

        /**
         * Test for FAILURE
         */
        $badEnum = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', ''],
            'required' => false,
            'default' => 'maybe',
        ]);

        $this->assertEquals(400, $badEnum['headers']['status-code']);
        $this->assertEquals('Invalid `elements` param: Value must a valid array no longer than 100 items and Value must be a valid string and at least 1 chars and no longer than 255 chars', $badEnum['body']['message']);

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testCreateIndexes(array $data): array
    {
        $databaseId = $data['databaseId'];

        $titleIndex = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'titleIndex',
            'type' => 'fulltext',
            'attributes' => ['title'],
        ]);

        $this->assertEquals(202, $titleIndex['headers']['status-code']);
        $this->assertEquals('titleIndex', $titleIndex['body']['key']);
        $this->assertEquals('fulltext', $titleIndex['body']['type']);
        $this->assertCount(1, $titleIndex['body']['attributes']);
        $this->assertEquals('title', $titleIndex['body']['attributes'][0]);

        $releaseYearIndex = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYear',
            'type' => 'key',
            'attributes' => ['releaseYear'],
        ]);

        $this->assertEquals(202, $releaseYearIndex['headers']['status-code']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['key']);
        $this->assertEquals('key', $releaseYearIndex['body']['type']);
        $this->assertCount(1, $releaseYearIndex['body']['attributes']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['attributes'][0]);

        $releaseWithDate1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYearDated',
            'type' => 'key',
            'attributes' => ['releaseYear', '$createdAt', '$updatedAt'],
        ]);

        $this->assertEquals(202, $releaseWithDate1['headers']['status-code']);
        $this->assertEquals('releaseYearDated', $releaseWithDate1['body']['key']);
        $this->assertEquals('key', $releaseWithDate1['body']['type']);
        $this->assertCount(3, $releaseWithDate1['body']['attributes']);
        $this->assertEquals('releaseYear', $releaseWithDate1['body']['attributes'][0]);
        $this->assertEquals('$createdAt', $releaseWithDate1['body']['attributes'][1]);
        $this->assertEquals('$updatedAt', $releaseWithDate1['body']['attributes'][2]);

        $releaseWithDate2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'birthDay',
            'type' => 'key',
            'attributes' => ['birthDay'],
        ]);

        $this->assertEquals(202, $releaseWithDate2['headers']['status-code']);
        $this->assertEquals('birthDay', $releaseWithDate2['body']['key']);
        $this->assertEquals('key', $releaseWithDate2['body']['type']);
        $this->assertCount(1, $releaseWithDate2['body']['attributes']);
        $this->assertEquals('birthDay', $releaseWithDate2['body']['attributes'][0]);

        // Test for failure
        $fulltextReleaseYear = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYearDated',
            'type' => 'fulltext',
            'attributes' => ['releaseYear'],
        ]);

        $this->assertEquals(400, $fulltextReleaseYear['headers']['status-code']);
        $this->assertEquals($fulltextReleaseYear['body']['message'], 'Attribute "releaseYear" cannot be part of a fulltext index, must be of type string');

        $noAttributes = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'none',
            'type' => 'key',
            'attributes' => [],
        ]);

        $this->assertEquals(400, $noAttributes['headers']['status-code']);
        $this->assertEquals($noAttributes['body']['message'], 'No attributes provided for index');

        $duplicates = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'duplicate',
            'type' => 'fulltext',
            'attributes' => ['releaseYear', 'releaseYear'],
        ]);

        $this->assertEquals(400, $duplicates['headers']['status-code']);
        $this->assertEquals($duplicates['body']['message'], 'Duplicate attributes provided');

        $tooLong = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'tooLong',
            'type' => 'key',
            'attributes' => ['description', 'tagline'],
        ]);

        $this->assertEquals(400, $tooLong['headers']['status-code']);
        $this->assertStringContainsString('Index length is longer than the maximum', $tooLong['body']['message']);

        $fulltextArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'ft',
            'type' => 'fulltext',
            'attributes' => ['actors'],
        ]);

        $this->assertEquals(400, $fulltextArray['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $fulltextArray['body']['message']);

        $actorsArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-actors',
            'type' => 'key',
            'attributes' => ['actors'],
        ]);

        $this->assertEquals(400, $actorsArray['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $actorsArray['body']['message']);

        $twoLevelsArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-ip-actors',
            'type' => 'key',
            'attributes' => ['releaseYear', 'actors'], // 2 levels
            'orders' => ['DESC', 'DESC'],
        ]);

        $this->assertEquals(400, $twoLevelsArray['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $twoLevelsArray['body']['message']);

        $unknown = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-unknown',
            'type' => 'key',
            'attributes' => ['Unknown'],
        ]);

        $this->assertEquals(400, $unknown['headers']['status-code']);
        $this->assertEquals('The attribute \'Unknown\' required for the index could not be found. Please confirm all your attributes are in the available state.', $unknown['body']['message']);

        $index1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'integers-order',
            'type' => 'key',
            'attributes' => ['integers'], // array attribute
            'orders' => ['DESC'], // Check order is removed in API
        ]);

        $this->assertEquals(400, $index1['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $index1['body']['message']);

        $index2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'integers-size',
            'type' => 'key',
            'attributes' => ['integers'], // array attribute
        ]);

        $this->assertEquals(400, $index2['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $index2['body']['message']);

        /**
         * Create Indexes by worker
         */
        sleep(2);

        $movies = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertIsArray($movies['body']['indexes']);
        $this->assertCount(4, $movies['body']['indexes']);
        $this->assertEquals($titleIndex['body']['key'], $movies['body']['indexes'][0]['key']);
        $this->assertEquals($releaseYearIndex['body']['key'], $movies['body']['indexes'][1]['key']);
        $this->assertEquals($releaseWithDate1['body']['key'], $movies['body']['indexes'][2]['key']);
        $this->assertEquals($releaseWithDate2['body']['key'], $movies['body']['indexes'][3]['key']);

        $this->assertEventually(function () use ($databaseId, $data) {
            $movies = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]));

            foreach ($movies['body']['indexes'] as $index) {
                $this->assertEquals('available', $index['status']);
            }

            return true;
        }, 60000, 500);

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testGetIndexByKeyWithLengths(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['moviesId'];

        // Test case for valid lengths
        $create = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthTestIndex',
            'type' => 'key',
            'attributes' => ['title','description'],
            'lengths' => [128,200]
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        // Fetch index and check correct lengths
        $index = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$collectionId}/indexes/lengthTestIndex", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $index['headers']['status-code']);
        $this->assertEquals('lengthTestIndex', $index['body']['key']);
        $this->assertEquals([128, 200], $index['body']['lengths']);

        // Test case for array attribute index (should be blocked)
        $create = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthOverrideTestIndex',
            'type' => 'key',
            'attributes' => ['actors'],
            'lengths' => [120]
        ]);
        $this->assertEquals(400, $create['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $create['body']['message']);

        // Test case for count of lengths greater than attributes (should throw 400)
        $create = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthCountExceededIndex',
            'type' => 'key',
            'attributes' => ['title'],
            'lengths' => [128, 128]
        ]);
        $this->assertEquals(400, $create['headers']['status-code']);

        // Test case for lengths exceeding total of 768
        $create = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthTooLargeIndex',
            'type' => 'key',
            'attributes' => ['title','description','tagline','actors'],
            'lengths' => [256,256,256,20]
        ]);

        $this->assertEquals(400, $create['headers']['status-code']);

        // Test case for negative length values
        $create = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'negativeLengthIndex',
            'type' => 'key',
            'attributes' => ['title'],
            'lengths' => [-1]
        ]);
        $this->assertEquals(400, $create['headers']['status-code']);
    }
    /**
     * @depends testCreateIndexes
     */
    public function testListIndexes(array $data): void
    {
        $databaseId = $data['databaseId'];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'queries' => [
                Query::equal('type', ['key'])->toString(),
                Query::limit(2)->toString()
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, \count($response['body']['indexes']));
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'queries' => [
                Query::select(['key'])->toString(),
            ],
        ]);
        $this->assertEquals(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);
        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateIndexes
     */
    public function testCreateDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'birthDay' => '1975-06-12 14:12:55+02:00',
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Spider-Man: Far From Home',
                'releaseYear' => 2019,
                'birthDay' => null,
                'actors' => [
                    'Tom Holland',
                    'Zendaya Maree Stoermer',
                    'Samuel Jackson',
                ],
                'integers' => [50,60]
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $document3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Spider-Man: Homecoming',
                'releaseYear' => 2017,
                'birthDay' => '1975-06-12 14:12:55 America/New_York',
                'duration' => 65,
                'actors' => [
                    'Tom Holland',
                    'Zendaya Maree Stoermer',
                ],
                'integers' => [50]
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $document4 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'releaseYear' => 2020, // Missing title, expect an 400 error
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $document1['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $document1['body']['$collectionId']);
        $this->assertArrayNotHasKey('$collection', $document1['body']);
        $this->assertEquals($databaseId, $document1['body']['$databaseId']);
        $this->assertEquals($document1['body']['title'], 'Captain America');
        $this->assertEquals($document1['body']['releaseYear'], 1944);
        $this->assertIsArray($document1['body']['$permissions']);
        $this->assertCount(3, $document1['body']['$permissions']);
        $this->assertCount(2, $document1['body']['actors']);
        $this->assertEquals($document1['body']['actors'][0], 'Chris Evans');
        $this->assertEquals($document1['body']['actors'][1], 'Samuel Jackson');
        $this->assertEquals($document1['body']['birthDay'], '1975-06-12T12:12:55.000+00:00');
        $this->assertTrue(array_key_exists('$sequence', $document1['body']));
        $this->assertIsInt($document1['body']['$sequence']);

        $this->assertEquals(201, $document2['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $document2['body']['$collectionId']);
        $this->assertArrayNotHasKey('$collection', $document2['body']);
        $this->assertEquals($databaseId, $document2['body']['$databaseId']);
        $this->assertEquals($document2['body']['title'], 'Spider-Man: Far From Home');
        $this->assertEquals($document2['body']['releaseYear'], 2019);
        $this->assertEquals($document2['body']['duration'], null);
        $this->assertIsArray($document2['body']['$permissions']);
        $this->assertCount(3, $document2['body']['$permissions']);
        $this->assertCount(3, $document2['body']['actors']);
        $this->assertEquals($document2['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($document2['body']['actors'][1], 'Zendaya Maree Stoermer');
        $this->assertEquals($document2['body']['actors'][2], 'Samuel Jackson');
        $this->assertEquals($document2['body']['birthDay'], null);
        $this->assertEquals($document2['body']['integers'][0], 50);
        $this->assertEquals($document2['body']['integers'][1], 60);
        $this->assertTrue(array_key_exists('$sequence', $document2['body']));

        $this->assertEquals(201, $document3['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $document3['body']['$collectionId']);
        $this->assertArrayNotHasKey('$collection', $document3['body']);
        $this->assertEquals($databaseId, $document3['body']['$databaseId']);
        $this->assertEquals($document3['body']['title'], 'Spider-Man: Homecoming');
        $this->assertEquals($document3['body']['releaseYear'], 2017);
        $this->assertEquals($document3['body']['duration'], 65);
        $this->assertIsArray($document3['body']['$permissions']);
        $this->assertCount(3, $document3['body']['$permissions']);
        $this->assertCount(2, $document3['body']['actors']);
        $this->assertEquals($document3['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($document3['body']['actors'][1], 'Zendaya Maree Stoermer');
        $this->assertEquals($document3['body']['birthDay'], '1975-06-12T18:12:55.000+00:00'); // UTC for NY
        $this->assertTrue(array_key_exists('$sequence', $document3['body']));

        $this->assertEquals(400, $document4['headers']['status-code']);

        return $data;
    }


    /**
     * @depends testCreateIndexes
     */
    public function testUpsertDocument(array $data): void
    {
        $databaseId = $data['databaseId'];
        $documentId = ID::unique();

        $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2000
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertCount(3, $document['body']['$permissions']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Thor: Ragnarok', $document['body']['title']);

        /**
         * Resubmit same document, nothing to update
         */
        $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2000,
                'integers' => [],
                'birthDay' => null,
                'duration' => null,
                'starringActors' => [],
                'actors' => [],
                'tagline' => '',
                'description' => '',
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $document['body']['title']);
        $this->assertCount(3, $document['body']['$permissions']);

        $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Love and Thunder',
                'releaseYear' => 2000
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals('Thor: Love and Thunder', $document['body']['title']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Thor: Love and Thunder', $document['body']['title']);

        // removing permission to read and delete
        $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Love and Thunder',
                'releaseYear' => 2000
            ],
            'permissions' => [
                Permission::update(Role::users())
            ],
        ]);
        // shouldn't be able to read as no read permission
        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        switch ($this->getSide()) {
            case 'client':
                $this->assertEquals(404, $document['headers']['status-code']);
                break;
            case 'server':
                $this->assertEquals(200, $document['headers']['status-code']);
                break;
        }
        // shouldn't be able to delete as no delete permission
        $document = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        // simulating for the client
        // the document should not be allowed to be deleted as needed downward
        if ($this->getSide() === 'client') {
            $this->assertEquals(401, $document['headers']['status-code']);
        }
        // giving the delete permission
        $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Love and Thunder',
                'releaseYear' => 2000
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users())
            ],
        ]);
        $document = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $document['headers']['status-code']);

        // relationship behaviour
        $person = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'person-upsert',
            'name' => 'person',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
                Permission::create(Role::users()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $person['headers']['status-code']);

        $library = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'library-upsert',
            'name' => 'library',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::create(Role::users()),
                Permission::delete(Role::users()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $library['headers']['status-code']);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fullName',
            'size' => 255,
            'required' => false,
        ]);

        sleep(1); // Wait for worker

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => 'library-upsert',
            'type' => Database::RELATION_ONE_TO_ONE,
            'key' => 'library',
            'twoWay' => true,
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);

        sleep(1); // Wait for worker

        $libraryName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $library['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'libraryName',
            'size' => 255,
            'required' => true,
        ]);

        sleep(1); // Wait for worker

        $this->assertEquals(202, $libraryName['headers']['status-code']);

        // upserting values
        $documentId = ID::unique();
        $person1 = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/'.$documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'library' => [
                    '$id' => 'library1',
                    '$permissions' => [
                        Permission::read(Role::users()),
                        Permission::update(Role::users()),
                        Permission::delete(Role::users()),
                    ],
                    'libraryName' => 'Library 1',
                ],
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ]
        ]);

        $this->assertEquals('Library 1', $person1['body']['library']['libraryName']);
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString(),
                Query::equal('library', ['library1'])->toString(),
            ],
        ]);

        $this->assertEquals(1, $documents['body']['total']);
        $this->assertEquals('Library 1', $documents['body']['documents'][0]['library']['libraryName']);


        $person1 = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/'.$documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'library' => [
                    '$id' => 'library1',
                    '$permissions' => [
                        Permission::read(Role::users()),
                        Permission::update(Role::users()),
                        Permission::delete(Role::users()),
                    ],
                    'libraryName' => 'Library 2',
                ],
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ]
        ]);

        // data should get updated
        $this->assertEquals('Library 2', $person1['body']['library']['libraryName']);
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString(),
                Query::equal('library', ['library1'])->toString(),
            ],
        ]);

        $this->assertEquals(1, $documents['body']['total']);
        $this->assertEquals('Library 2', $documents['body']['documents'][0]['library']['libraryName']);


        // data should get added
        $person1 = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/'.ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'library' => [
                    '$id' => 'library2',
                    '$permissions' => [
                        Permission::read(Role::users()),
                        Permission::update(Role::users()),
                        Permission::delete(Role::users()),
                    ],
                    'libraryName' => 'Library 2',
                ],
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ]
        ]);

        $this->assertEquals('Library 2', $person1['body']['library']['libraryName']);
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString()
            ],
        ]);
        $this->assertEquals(2, $documents['body']['total']);

        // test without passing permissions
        $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2000
            ]
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $document['body']['title']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $document['headers']['status-code']);

        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleteResponse['headers']['status-code']);

        if ($this->getSide() === 'client') {
            // Skipped on server side: Creating a document with no permissions results in an empty permissions array, whereas on client side it assigns permissions to the current user

            // test without passing permissions
            $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                    'releaseYear' => 2000
                ]
            ]);

            $this->assertEquals(200, $document['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $document['body']['title']);
            $this->assertCount(3, $document['body']['$permissions']);
            $permissionsCreated = $document['body']['$permissions'];
            // checking the default created permission
            $defaultPermission = [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id']))
            ];
            // ignoring the order of the permission and checking the permissions
            $this->assertEqualsCanonicalizing($defaultPermission, $permissionsCreated);

            $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(200, $document['headers']['status-code']);

            // updating the created doc
            $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                    'releaseYear' => 2002
                ]
            ]);
            $this->assertEquals(200, $document['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $document['body']['title']);
            $this->assertEquals(2002, $document['body']['releaseYear']);
            $this->assertCount(3, $document['body']['$permissions']);
            $this->assertEquals($permissionsCreated, $document['body']['$permissions']);

            // removing the delete permission
            $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                    'releaseYear' => 2002
                ],
                'permissions' => [
                    Permission::update(Role::user($this->getUser()['$id']))
                ]
            ]);
            $this->assertEquals(200, $document['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $document['body']['title']);
            $this->assertEquals(2002, $document['body']['releaseYear']);
            $this->assertCount(1, $document['body']['$permissions']);

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(401, $deleteResponse['headers']['status-code']);

            // giving the delete permission
            $document = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                    'releaseYear' => 2002
                ],
                'permissions' => [
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id']))
                ]
            ]);
            $this->assertEquals(200, $document['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $document['body']['title']);
            $this->assertEquals(2002, $document['body']['releaseYear']);
            $this->assertCount(2, $document['body']['$permissions']);

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(204, $deleteResponse['headers']['status-code']);

            // upsertion for the related document without passing permissions
            // data should get added
            $newPersonId = ID::unique();
            $personNoPerm = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $newPersonId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'library' => [
                        '$id' => 'library3',
                        'libraryName' => 'Library 3',
                    ],
                ],
            ]);

            $this->assertEquals('Library 3', $personNoPerm['body']['library']['libraryName']);
            $this->assertCount(3, $personNoPerm['body']['library']['$permissions']);
            $this->assertCount(3, $personNoPerm['body']['$permissions']);
            $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'queries' => [
                    Query::select(['fullName', 'library.*'])->toString()
                ],
            ]);
            $this->assertGreaterThanOrEqual(1, $documents['body']['total']);
            $documentsDetails = $documents['body']['documents'];
            foreach ($documentsDetails as $doc) {
                $this->assertCount(3, $doc['$permissions']);
            }
            $found = false;
            foreach ($documents['body']['documents'] as $doc) {
                if (isset($doc['library']['libraryName']) && $doc['library']['libraryName'] === 'Library 3') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Library 3 should be present in the upserted documents.');

            // Fetch the related library and assert on its permissions (should be default/inherited)
            $library3 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $library['body']['$id'] . '/documents/library3', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $library3['headers']['status-code']);
            $this->assertEquals('Library 3', $library3['body']['libraryName']);
            $this->assertArrayHasKey('$permissions', $library3['body']);
            $this->assertCount(3, $library3['body']['$permissions']);
            $this->assertNotEmpty($library3['body']['$permissions']);

            // Readonly attributes are ignored
            $personNoPerm = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $newPersonId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    '$id' => 'some-other-id',
                    '$collectionId' => 'some-other-collection',
                    '$databaseId' => 'some-other-database',
                    '$createdAt' => '2024-01-01T00:00:00Z',
                    '$updatedAt' => '2024-01-01T00:00:00Z',
                    'library' => [
                        '$id' => 'library3',
                        'libraryName' => 'Library 3',
                        '$createdAt' => '2024-01-01T00:00:00Z',
                        '$updatedAt' => '2024-01-01T00:00:00Z',
                    ],
                ],
            ]);

            $update = $personNoPerm;
            $update['body']['$id'] = 'random';
            $update['body']['$sequence'] = 123;
            $update['body']['$databaseId'] = 'random';
            $update['body']['$collectionId'] = 'random';
            $update['body']['$createdAt'] = '2024-01-01T00:00:00.000+00:00';
            $update['body']['$updatedAt'] = '2024-01-01T00:00:00.000+00:00';

            $upserted = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $newPersonId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => $update['body']
            ]);

            $this->assertEquals(200, $upserted['headers']['status-code']);
            $this->assertEquals($personNoPerm['body']['$id'], $upserted['body']['$id']);
            $this->assertEquals($personNoPerm['body']['$collectionId'], $upserted['body']['$collectionId']);
            $this->assertEquals($personNoPerm['body']['$databaseId'], $upserted['body']['$databaseId']);
            $this->assertEquals($personNoPerm['body']['$sequence'], $upserted['body']['$sequence']);

            if ($this->getSide() === 'client') {
                $this->assertEquals($personNoPerm['body']['$createdAt'], $upserted['body']['$createdAt']);
                $this->assertNotEquals('2024-01-01T00:00:00.000+00:00', $upserted['body']['$updatedAt']);
            } else {
                $this->assertEquals('2024-01-01T00:00:00.000+00:00', $upserted['body']['$createdAt']);
                $this->assertEquals('2024-01-01T00:00:00.000+00:00', $upserted['body']['$updatedAt']);
            }
        }
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocuments(array $data): array
    {
        $databaseId = $data['databaseId'];
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][2]['releaseYear']);
        $this->assertTrue(array_key_exists('$sequence', $documents['body']['documents'][0]));
        $this->assertTrue(array_key_exists('$sequence', $documents['body']['documents'][1]));
        $this->assertTrue(array_key_exists('$sequence', $documents['body']['documents'][2]));
        $this->assertCount(3, $documents['body']['documents']);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertEquals($data['moviesId'], $document['$collectionId']);
            $this->assertArrayNotHasKey('$collection', $document);
            $this->assertEquals($databaseId, $document['$databaseId']);
        }

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1944, $documents['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);

        // changing description attribute to be null by default instead of empty string
        $patchNull = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/attributes/string/description', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => null,
            'required' => false,
        ]);
        // creating a dummy doc with null description
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Dummy',
                'releaseYear' => 1944,
                'birthDay' => '1975-06-12 14:12:55+02:00',
                'actors' => [
                    'Dummy',
                ],
            ]
        ]);

        $this->assertEquals(201, $document1['headers']['status-code']);
        // fetching docs with cursor after the dummy doc with order attr description which is null
        $documentsPaginated = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('dummy')->toString(),
                Query::cursorAfter(new Document(['$id' => $document1['body']['$id']]))->toString()
            ],
        ]);
        // should throw 400 as the order attr description of the selected doc is null
        $this->assertEquals(400, $documentsPaginated['headers']['status-code']);

        // deleting the dummy doc created
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $document1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        return ['documents' => $documents['body']['documents'], 'databaseId' => $databaseId];
    }

    /**
     * @depends testListDocuments
     */
    public function testGetDocument(array $data): void
    {
        $databaseId = $data['databaseId'];
        foreach ($data['documents'] as $document) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $document['$collectionId'] . '/documents/' . $document['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($response['body']['$id'], $document['$id']);
            $this->assertEquals($document['$collectionId'], $response['body']['$collectionId']);
            $this->assertArrayNotHasKey('$collection', $response['body']);
            $this->assertEquals($document['$databaseId'], $response['body']['$databaseId']);
            $this->assertEquals($response['body']['title'], $document['title']);
            $this->assertEquals($response['body']['releaseYear'], $document['releaseYear']);
            $this->assertEquals($response['body']['$permissions'], $document['$permissions']);
            $this->assertEquals($response['body']['birthDay'], $document['birthDay']);
            $this->assertTrue(array_key_exists('$sequence', $response['body']));
            $this->assertFalse(array_key_exists('$tenant', $response['body']));
        }
    }

    /**
     * @depends testListDocuments
     */
    public function testGetDocumentWithQueries(array $data): void
    {
        $databaseId = $data['databaseId'];
        $document = $data['documents'][0];

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $document['$collectionId'] . '/documents/' . $document['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['title', 'releaseYear', '$id'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($document['title'], $response['body']['title']);
        $this->assertEquals($document['releaseYear'], $response['body']['releaseYear']);
        $this->assertArrayNotHasKey('birthDay', $response['body']);
        $sequence = $response['body']['$sequence'];

        // Query by sequence
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $document['$collectionId'] . '/documents/' . $document['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$sequence', [$sequence])
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($document['title'], $response['body']['title']);
        $this->assertEquals($document['releaseYear'], $response['body']['releaseYear']);
        $this->assertTrue(array_key_exists('$sequence', $response['body']));
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsAfterPagination(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test after without order.
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals('Captain America', $base['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['documents'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['documents'][2]['title']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['documents'][0]['$id']]))->toString()
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($base['body']['documents'][1]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertEquals($base['body']['documents'][2]['$id'], $documents['body']['documents'][1]['$id']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['documents'][2]['$id']]))->toString()
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEmpty($documents['body']['documents']);

        /**
         * Test with ASC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['documents'][1]['$id']]))->toString(),
                Query::orderAsc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($base['body']['documents'][2]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        /**
         * Test with DESC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['documents'][1]['$id']]))->toString(),
                Query::orderDesc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($base['body']['documents'][2]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        /**
         * Test after with unknown document.
         */
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);

        /**
         * Test null value for cursor
         */

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"cursorAfter","values":[null]}',
            ],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsBeforePagination(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test before without order.
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals('Captain America', $base['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['documents'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['documents'][2]['title']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['documents'][2]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($base['body']['documents'][0]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertEquals($base['body']['documents'][1]['$id'], $documents['body']['documents'][1]['$id']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['documents'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEmpty($documents['body']['documents']);

        /**
         * Test with ASC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['documents'][1]['$id']]))->toString(),
                Query::orderAsc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($base['body']['documents'][0]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        /**
         * Test with DESC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['documents'][1]['$id']]))->toString(),
                Query::orderDesc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals($base['body']['documents'][0]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsLimitAndOffset(array $data): array
    {
        $databaseId = $data['databaseId'];
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
                Query::limit(2)->toString(),
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testDocumentsListQueries(array $data): array
    {
        $databaseId = $data['databaseId'];
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', 'Captain America')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', [$documents['body']['documents'][0]['$id']])->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', 'Homecoming')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', 'spider')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"contains","attribute":"title","values":[bad]}'
            ],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);
        $this->assertEquals('Invalid query: Syntax error', $documents['body']['message']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('title', ['spi'])->toString(), // like query
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2, $documents['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('releaseYear', [1944])->toString(),
            ],
        ]);

        $this->assertCount(1, $documents['body']['documents']);
        $this->assertEquals('Captain America', $documents['body']['documents'][0]['title']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::notEqual('releaseYear', 1944)->toString(),
            ],
        ]);

        $this->assertCount(2, $documents['body']['documents']);
        $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['title']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('$createdAt', '1976-06-12')->toString(),
            ],
        ]);

        $this->assertCount(3, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('$createdAt', '1976-06-12')->toString(),
            ],
        ]);

        $this->assertCount(0, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('actors', ['Tom Holland', 'Samuel Jackson'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(3, $documents['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('actors', ['Tom'])->toString(), // Full-match not like
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('birthDay', '16/01/2024 12:00:00AM')->toString(),
            ],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);
        $this->assertEquals('Invalid query: Query value is invalid for attribute "birthDay"', $documents['body']['message']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('birthDay', '1960-01-01 10:10:10+02:30')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals('1975-06-12T12:12:55.000+00:00', $documents['body']['documents'][0]['birthDay']);
        $this->assertEquals('1975-06-12T18:12:55.000+00:00', $documents['body']['documents'][1]['birthDay']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::isNull('integers')->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1, $documents['body']['total']);

        /**
         * Test for Failure
         */
        $conditions = [];

        for ($i = 0; $i < APP_DATABASE_QUERY_MAX_VALUES + 1; $i++) {
            $conditions[] = $i;
        }

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('releaseYear', $conditions)->toString(),
            ],
        ]);
        $this->assertEquals(400, $documents['headers']['status-code']);
        $this->assertEquals('Invalid query: Query on attribute has greater than '.APP_DATABASE_QUERY_MAX_VALUES.' values: releaseYear', $documents['body']['message']);

        $value = '';

        for ($i = 0; $i < 101; $i++) {
            $value .= "[" . $i . "] Too long title to cross 2k chars query limit ";
        }

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', $value)->toString(),
            ],
        ]);

        // Todo: Not sure what to do we with Query length Test VS old? JSON validator will fails if query string will be truncated?
        //$this->assertEquals(400, $documents['headers']['status-code']);

        // Todo: Disabled for CL - Uncomment after ProxyDatabase cleanup for find method
        // $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'queries' => [
        //         Query::search('actors', 'Tom')->toString(),
        //     ],
        // ]);
        // $this->assertEquals(400, $documents['headers']['status-code']);
        // $this->assertEquals('Invalid query: Cannot query search on attribute "actors" because it is an array.', $documents['body']['message']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testUpdateDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Thor: Ragnaroc',
                'releaseYear' => 2017,
                'birthDay' => '1976-06-12 14:12:55',
                'actors' => [],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $document['body']['$collectionId']);
        $this->assertArrayNotHasKey('$collection', $document['body']);
        $this->assertEquals($databaseId, $document['body']['$databaseId']);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnaroc');
        $this->assertEquals($document['body']['releaseYear'], 2017);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($document['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($document['body']['birthDay']));
        $this->assertContains(Permission::read(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);

        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals($document['body']['$id'], $id);
        $this->assertEquals($data['moviesId'], $document['body']['$collectionId']);
        $this->assertArrayNotHasKey('$collection', $document['body']);
        $this->assertEquals($databaseId, $document['body']['$databaseId']);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);
        $this->assertContains(Permission::read(Role::users()), $document['body']['$permissions']);
        $this->assertContains(Permission::update(Role::users()), $document['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::users()), $document['body']['$permissions']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $id = $document['body']['$id'];

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $document['body']['$collectionId']);
        $this->assertArrayNotHasKey('$collection', $document['body']);
        $this->assertEquals($databaseId, $document['body']['$databaseId']);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timestamp' => DateTime::formatTz(DateTime::now()),
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Test readonly attributes are ignored
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timestamp' => DateTime::formatTz(DateTime::now()),
        ], $this->getHeaders()), [
            'data' => [
                '$id' => 'newId',
                '$sequence' => 9999,
                '$collectionId' => 'newCollectionId',
                '$databaseId' => 'newDatabaseId',
                '$createdAt' => '2024-01-01T00:00:00.000+00:00',
                '$updatedAt' => '2024-01-01T00:00:00.000+00:00',
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($id, $response['body']['$id']);
        $this->assertEquals($data['moviesId'], $response['body']['$collectionId']);
        $this->assertEquals($databaseId, $response['body']['$databaseId']);
        $this->assertNotEquals(9999, $response['body']['$sequence']);

        if ($this->getSide() === 'client') {
            $this->assertNotEquals('2024-01-01T00:00:00.000+00:00', $response['body']['$createdAt']);
            $this->assertNotEquals('2024-01-01T00:00:00.000+00:00', $response['body']['$updatedAt']);
        } else {
            $this->assertEquals('2024-01-01T00:00:00.000+00:00', $response['body']['$createdAt']);
            $this->assertEquals('2024-01-01T00:00:00.000+00:00', $response['body']['$updatedAt']);
        }

        return [];
    }

    public function testOperators(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database for Operators'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Operator Tests',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'releaseYear',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'duration',
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'integers',
            'required' => false,
            'array' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'tagline',
            'size' => 512,
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'birthDay',
            'required' => false,
        ]);

        // Wait for attributes to be created
        sleep(2);

        // Create a document to test operators
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Operator Test',
                'releaseYear' => 2020,
                'duration' => 120,
                'actors' => ['Actor1', 'Actor2'],
                'integers' => [10, 20],
                'tagline' => 'Original',
                'birthDay' => '2020-01-01 12:00:00',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $documentId = $document['body']['$id'];

        // Test increment operator on integer
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'releaseYear' => Operator::increment(5)->toString(),
                'duration' => Operator::increment(10)->toString(),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(2025, $updated['body']['releaseYear']);
        $this->assertEquals(130, $updated['body']['duration']);

        // Test decrement operator
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'releaseYear' => Operator::decrement(3)->toString(),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(2022, $updated['body']['releaseYear']);

        // Test array append operator
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'actors' => Operator::arrayAppend(['Actor3'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(['Actor1', 'Actor2', 'Actor3'], $updated['body']['actors']);

        // Test array prepend operator
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'actors' => Operator::arrayPrepend(['Actor0'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(['Actor0', 'Actor1', 'Actor2', 'Actor3'], $updated['body']['actors']);

        // Test string concat operator
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'tagline' => Operator::stringConcat(' Appended')->toString(),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals('Original Appended', $updated['body']['tagline']);

        // Test multiple operators in a single update
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'releaseYear' => Operator::increment(1)->toString(),
                'integers' => Operator::arrayAppend([30])->toString(),
            ],
        ]);

        $this->assertEquals(200, $updated['headers']['status-code']);
        $this->assertEquals(2023, $updated['body']['releaseYear']);
        $this->assertEquals([10, 20, 30], $updated['body']['integers']);

        // Test upsert with operators
        $upsertId = ID::unique();
        $upserted = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $upsertId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Upsert Test',
                'releaseYear' => 2020,
                'actors' => [],
                'birthDay' => '2020-01-01 12:00:00',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(200, $upserted['headers']['status-code']);

        $upserted = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $upsertId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Upsert Test Updated',
                'releaseYear' => Operator::increment(5)->toString(),
                'actors' => [],
                'birthDay' => '2020-01-01 12:00:00',
            ],
        ]);

        $this->assertEquals(200, $upserted['headers']['status-code']);
        $this->assertEquals(2025, $upserted['body']['releaseYear']);
    }

    public function testBulkOperators(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database for Bulk Operators'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Operator Tests',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::users()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'releaseYear',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'birthDay',
            'required' => false,
        ]);

        // Wait for attributes to be created
        sleep(2);

        // Create multiple documents
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Bulk Test 1',
                'releaseYear' => 2020,
                'actors' => ['Actor1'],
                'birthDay' => '2020-01-01 12:00:00',
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Bulk Test 2',
                'releaseYear' => 2021,
                'actors' => ['Actor2'],
                'birthDay' => '2020-01-01 12:00:00',
            ],
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        $this->assertEquals(201, $document1['headers']['status-code']);
        $this->assertEquals(201, $document2['headers']['status-code']);

        // Test bulk update with operators
        $bulkUpdate = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'releaseYear' => Operator::increment(10)->toString(),
            ],
            'queries' => [
                Query::startsWith('title', 'Bulk Test')->toString(),
            ],
        ]);

        $this->assertEquals(200, $bulkUpdate['headers']['status-code']);
        $this->assertGreaterThanOrEqual(2, $bulkUpdate['body']['total']);

        // Verify the updates
        $verify1 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $verify1['headers']['status-code']);
        $this->assertEquals(2030, $verify1['body']['releaseYear']);

        $verify2 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $verify2['headers']['status-code']);
        $this->assertEquals(2031, $verify2['body']['releaseYear']);
    }

    /**
     * @depends testCreateDocument
     */
    public function testDeleteDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2017,
                'birthDay' => '1975-06-12 14:12:55',
                'actors' => [],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals(201, $document['headers']['status-code']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $document['headers']['status-code']);

        $document = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $document['headers']['status-code']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $document['headers']['status-code']);

        return $data;
    }

    public function testInvalidDocumentStructure(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'InvalidDocumentDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('InvalidDocumentDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'invalidDocumentStructure',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals('invalidDocumentStructure', $collection['body']['name']);

        $collectionId = $collection['body']['$id'];

        $email = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false,
        ]);

        $enum = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', 'maybe'],
            'required' => false,
        ]);

        $ip = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false,
        ]);

        $url = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'size' => 256,
            'required' => false,
        ]);

        $range = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'range',
            'required' => false,
            'min' => 1,
            'max' => 10,
        ]);

        // TODO@kodumbeats min and max are rounded in error message
        $floatRange = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'floatRange',
            'required' => false,
            'min' => 1.1,
            'max' => 1.4,
        ]);

        $probability = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'probability',
            'required' => false,
            'default' => 0,
            'min' => 0,
            'max' => 1,
        ]);

        $upperBound = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'upperBound',
            'required' => false,
            'max' => 10,
        ]);

        $lowerBound = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lowerBound',
            'required' => false,
            'min' => 5,
        ]);

        /**
         * Test for failure
         */

        $invalidRange = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'invalidRange',
            'required' => false,
            'min' => 4,
            'max' => 3,
        ]);

        $defaultArray = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'defaultArray',
            'required' => false,
            'default' => 42,
            'array' => true,
        ]);

        $defaultRequired = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => ID::custom('defaultRequired'),
            'required' => true,
            'default' => 12
        ]);

        $enumDefault = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => ID::custom('enumDefault'),
            'elements' => ['north', 'west'],
            'default' => 'south'
        ]);

        $enumDefaultStrict = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => ID::custom('enumDefault'),
            'elements' => ['north', 'west'],
            'default' => 'NORTH'
        ]);

        $goodDatetime = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'birthDay',
            'required' => false,
            'default' => null
        ]);

        $datetimeDefault = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'badBirthDay',
            'required' => false,
            'default' => 'bad'
        ]);

        $this->assertEquals(202, $email['headers']['status-code']);
        $this->assertEquals(202, $ip['headers']['status-code']);
        $this->assertEquals(202, $url['headers']['status-code']);
        $this->assertEquals(202, $range['headers']['status-code']);
        $this->assertEquals(202, $floatRange['headers']['status-code']);
        $this->assertEquals(202, $probability['headers']['status-code']);
        $this->assertEquals(202, $upperBound['headers']['status-code']);
        $this->assertEquals(202, $lowerBound['headers']['status-code']);
        $this->assertEquals(202, $enum['headers']['status-code']);
        $this->assertEquals(202, $goodDatetime['headers']['status-code']);
        $this->assertEquals(400, $invalidRange['headers']['status-code']);
        $this->assertEquals(400, $defaultArray['headers']['status-code']);
        $this->assertEquals(400, $defaultRequired['headers']['status-code']);
        $this->assertEquals(400, $enumDefault['headers']['status-code']);
        $this->assertEquals(400, $enumDefaultStrict['headers']['status-code']);
        $this->assertEquals('Minimum value must be lesser than maximum value', $invalidRange['body']['message']);
        $this->assertEquals('Cannot set default value for array attributes', $defaultArray['body']['message']);
        $this->assertEquals(400, $datetimeDefault['headers']['status-code']);
        // wait for worker to add attributes
        sleep(3);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), []);

        $this->assertCount(10, $collection['body']['attributes']);

        /**
         * Test for successful validation
         */

        $goodEmail = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'email' => 'user@example.com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodEnum = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'enum' => 'yes',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodIp = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'ip' => '1.1.1.1',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodUrl = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'url' => 'http://www.example.com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodRange = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'range' => 3,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodFloatRange = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'floatRange' => 1.4,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodProbability = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'probability' => 0.99999,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $notTooHigh = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'upperBound' => 8,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $notTooLow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'lowerBound' => 8,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $this->assertEquals(201, $goodEmail['headers']['status-code']);
        $this->assertEquals(201, $goodEnum['headers']['status-code']);
        $this->assertEquals(201, $goodIp['headers']['status-code']);
        $this->assertEquals(201, $goodUrl['headers']['status-code']);
        $this->assertEquals(201, $goodRange['headers']['status-code']);
        $this->assertEquals(201, $goodFloatRange['headers']['status-code']);
        $this->assertEquals(201, $goodProbability['headers']['status-code']);
        $this->assertEquals(201, $notTooHigh['headers']['status-code']);
        $this->assertEquals(201, $notTooLow['headers']['status-code']);

        /*
         * Test that custom validators reject documents
         */

        $badEmail = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'email' => 'user@@example.com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badEnum = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'enum' => 'badEnum',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badIp = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'ip' => '1.1.1.1.1',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badUrl = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'url' => 'example...com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badRange = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'range' => 11,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badFloatRange = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'floatRange' => 2.5,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badProbability = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'probability' => 1.1,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $tooHigh = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'upperBound' => 11,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $tooLow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'lowerBound' => 3,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badTime = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'birthDay' => '2020-10-10 27:30:10+01:00',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals(400, $badEmail['headers']['status-code']);
        $this->assertEquals(400, $badEnum['headers']['status-code']);
        $this->assertEquals(400, $badIp['headers']['status-code']);
        $this->assertEquals(400, $badUrl['headers']['status-code']);
        $this->assertEquals(400, $badRange['headers']['status-code']);
        $this->assertEquals(400, $badFloatRange['headers']['status-code']);
        $this->assertEquals(400, $badProbability['headers']['status-code']);
        $this->assertEquals(400, $tooHigh['headers']['status-code']);
        $this->assertEquals(400, $tooLow['headers']['status-code']);
        $this->assertEquals(400, $badTime['headers']['status-code']);
        $this->assertEquals('Invalid document structure: Attribute "email" has invalid format. Value must be a valid email address', $badEmail['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "enum" has invalid format. Value must be one of (yes, no, maybe)', $badEnum['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "ip" has invalid format. Value must be a valid IP address', $badIp['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "url" has invalid format. Value must be a valid URL', $badUrl['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "range" has invalid format. Value must be a valid range between 1 and 10', $badRange['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "floatRange" has invalid format. Value must be a valid range between 1 and 1', $badFloatRange['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "probability" has invalid format. Value must be a valid range between 0 and 1', $badProbability['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "upperBound" has invalid format. Value must be a valid range between -9,223,372,036,854,775,808 and 10', $tooHigh['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "lowerBound" has invalid format. Value must be a valid range between 5 and 9,223,372,036,854,775,807', $tooLow['body']['message']);
    }

    /**
     * @depends testDeleteDocument
     */
    public function testDefaultPermissions(array $data): array
    {
        $databaseId = $data['databaseId'];
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [],
            ],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals($document['body']['title'], 'Captain America');
        $this->assertEquals($document['body']['releaseYear'], 1944);
        $this->assertIsArray($document['body']['$permissions']);

        if ($this->getSide() == 'client') {
            $this->assertCount(3, $document['body']['$permissions']);
            $this->assertContains(Permission::read(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
            $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
            $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
        }

        if ($this->getSide() == 'server') {
            $this->assertCount(0, $document['body']['$permissions']);
            $this->assertEquals([], $document['body']['$permissions']);
        }

        // Updated Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Captain America 2',
                'releaseYear' => 1945,
                'actors' => [],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id']))
            ],
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals($document['body']['title'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        // This differs from the old permissions model because we don't inherit
        // existing document permissions on update, unless none were supplied,
        // so that specific types can be removed if wanted.
        $this->assertCount(2, $document['body']['$permissions']);
        $this->assertEquals([
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
        ], $document['body']['$permissions']);

        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals($document['body']['title'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        $this->assertCount(2, $document['body']['$permissions']);
        $this->assertEquals([
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
        ], $document['body']['$permissions']);

        // Reset Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Captain America 3',
                'releaseYear' => 1946,
                'actors' => [],
            ],
            'permissions' => [],
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals($document['body']['title'], 'Captain America 3');
        $this->assertEquals($document['body']['releaseYear'], 1946);
        $this->assertCount(0, $document['body']['$permissions']);
        $this->assertEquals([], $document['body']['$permissions']);

        // Check client side can no longer read the document.
        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        switch ($this->getSide()) {
            case 'client':
                $this->assertEquals(404, $document['headers']['status-code']);
                break;
            case 'server':
                $this->assertEquals(200, $document['headers']['status-code']);
                break;
        }

        return $data;
    }

    public function testEnforceCollectionAndDocumentPermissions(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'EnforceCollectionAndDocumentPermissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('EnforceCollectionAndDocumentPermissions', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $user = $this->getUser()['$id'];
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'enforceCollectionAndDocumentPermissions',
            'documentSecurity' => true,
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::create(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'enforceCollectionAndDocumentPermissions');
        $this->assertEquals($collection['body']['documentSecurity'], true);

        $collectionId = $collection['body']['$id'];

        sleep(2);

        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute',
            'size' => 64,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code'], 202);
        $this->assertEquals('attribute', $attribute['body']['key']);

        // wait for db to add attribute
        sleep(2);

        $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'attributes' => [$attribute['body']['key']],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        // wait for db to add attribute
        sleep(2);

        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'attribute' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $document1['headers']['status-code']);

        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'attribute' => 'one',
            ],
            'permissions' => [
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $document2['headers']['status-code']);

        $document3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'attribute' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom('other'))),
                Permission::update(Role::user(ID::custom('other'))),
            ],
        ]);

        $this->assertEquals(201, $document3['headers']['status-code']);

        $documentsUser1 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the collection so can get any document
        $this->assertEquals(3, $documentsUser1['body']['total']);
        $this->assertCount(3, $documentsUser1['body']['documents']);

        $document3GetWithCollectionRead = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the collection so can get any document
        $this->assertEquals(200, $document3GetWithCollectionRead['headers']['status-code']);

        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';
        $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::custom('other'),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);
        $session2 = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $session2 = $session2['cookies']['a_session_' . $this->getProject()['$id']];

        $document3GetWithDocumentRead = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no collection permissions but has read permission for this document
        $this->assertEquals(200, $document3GetWithDocumentRead['headers']['status-code']);

        $document2GetFailure = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document2['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no collection or document permissions for this document
        $this->assertEquals(404, $document2GetFailure['headers']['status-code']);

        $documentsUser2 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no collection permissions but has read permission for one document
        $this->assertEquals(1, $documentsUser2['body']['total']);
        $this->assertCount(1, $documentsUser2['body']['documents']);
    }

    public function testEnforceCollectionPermissions(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'EnforceCollectionPermissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('EnforceCollectionPermissions', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $user = $this->getUser()['$id'];
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'enforceCollectionPermissions',
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::create(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'enforceCollectionPermissions');
        $this->assertEquals($collection['body']['documentSecurity'], false);

        $collectionId = $collection['body']['$id'];

        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute',
            'size' => 64,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code'], 202);
        $this->assertEquals('attribute', $attribute['body']['key']);

        \sleep(2);

        $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'attributes' => [$attribute['body']['key']],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        \sleep(2);

        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'attribute' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $document1['headers']['status-code']);

        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'attribute' => 'one',
            ],
            'permissions' => [
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $document2['headers']['status-code']);

        $document3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'attribute' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom('other2'))),
                Permission::update(Role::user(ID::custom('other2'))),
            ],
        ]);

        $this->assertEquals(201, $document3['headers']['status-code']);

        $documentsUser1 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the collection so can get any document
        $this->assertEquals(3, $documentsUser1['body']['total']);
        $this->assertCount(3, $documentsUser1['body']['documents']);

        $document3GetWithCollectionRead = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the collection so can get any document
        $this->assertEquals(200, $document3GetWithCollectionRead['headers']['status-code']);

        $email = uniqid() . 'user2@localhost.test';
        $password = 'password';
        $name = 'User Name';
        $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::custom('other2'),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);
        $session2 = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $session2 = $session2['cookies']['a_session_' . $this->getProject()['$id']];

        $document3GetWithDocumentRead = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // other2 has no collection permissions and document permissions are disabled
        $this->assertEquals(404, $document3GetWithDocumentRead['headers']['status-code']);

        $documentsUser2 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // other2 has no collection permissions and document permissions are disabled
        $this->assertEquals(401, $documentsUser2['headers']['status-code']);

        // Enable document permissions
        $collection = $this->client->call(CLient::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => $collection['body']['name'],
            'documentSecurity' => true,
        ]);

        $documentsUser2 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no collection permissions read access to one document
        $this->assertEquals(1, $documentsUser2['body']['total']);
        $this->assertCount(1, $documentsUser2['body']['documents']);
    }

    /**
     * @depends testDefaultPermissions
     */
    public function testUniqueIndexDuplicate(array $data): array
    {
        $databaseId = $data['databaseId'];
        $uniqueIndex = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unique_title',
            'type' => 'unique',
            'attributes' => ['title'],
        ]);

        $this->assertEquals(202, $uniqueIndex['headers']['status-code']);

        sleep(2);

        // test for failure
        $duplicate = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $this->assertEquals(409, $duplicate['headers']['status-code']);

        // Test for exception when updating document to conflict
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America 5',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);

        // Test for exception when updating document to conflict
        $duplicate = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $this->assertEquals(409, $duplicate['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUniqueIndexDuplicate
     */
    public function testPersistentCreatedAt(array $data): array
    {
        $headers = $this->getSide() === 'client' ? array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()) : [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['moviesId'] . '/documents', $headers, [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Creation Date Test',
                'releaseYear' => 2000
            ]
        ]);

        $this->assertEquals($document['body']['title'], 'Creation Date Test');

        $documentId = $document['body']['$id'];
        $createdAt = $document['body']['$createdAt'];
        $updatedAt = $document['body']['$updatedAt'];

        \sleep(1);

        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, $headers, [
            'data' => [
                'title' => 'Updated Date Test',
            ]
        ]);

        $updatedAtSecond = $document['body']['$updatedAt'];

        $this->assertEquals($document['body']['title'], 'Updated Date Test');
        $this->assertEquals($document['body']['$createdAt'], $createdAt);
        $this->assertNotEquals($document['body']['$updatedAt'], $updatedAt);

        \sleep(1);

        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, $headers, [
            'data' => [
                'title' => 'Again Updated Date Test',
                '$createdAt' => '2022-08-01 13:09:23.040',
                '$updatedAt' => '2022-08-01 13:09:23.050'
            ]
        ]);
        if ($this->getSide() === 'client') {
            $this->assertEquals($document['body']['title'], 'Again Updated Date Test');
            $this->assertNotEquals($document['body']['$createdAt'], DateTime::formatTz('2022-08-01 13:09:23.040'));
            $this->assertNotEquals($document['body']['$updatedAt'], DateTime::formatTz('2022-08-01 13:09:23.050'));
        } else {
            $this->assertEquals($document['body']['title'], 'Again Updated Date Test');
            $this->assertEquals($document['body']['$createdAt'], DateTime::formatTz('2022-08-01 13:09:23.040'));
            $this->assertEquals($document['body']['$updatedAt'], DateTime::formatTz('2022-08-01 13:09:23.050'));

        }

        return $data;
    }

    public function testUpdatePermissionsWithEmptyPayload(): array
    {
        // Create Database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Empty Permissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);

        $databaseId = $database['body']['$id'];

        // Create collection
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::create(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        $moviesId = $movies['body']['$id'];

        // create attribute
        $title = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $moviesId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $title['headers']['status-code']);

        // wait for database worker to create attributes
        sleep(2);

        // add document
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertCount(3, $document['body']['$permissions']);
        $this->assertContains(Permission::read(Role::any()), $document['body']['$permissions']);
        $this->assertContains(Permission::update(Role::any()), $document['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::any()), $document['body']['$permissions']);

        // Send only read permission
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $moviesId . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertCount(1, $document['body']['$permissions']);

        // Send only mutation permissions
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $moviesId . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ],
        ]);

        if ($this->getSide() == 'server') {
            $this->assertEquals(200, $document['headers']['status-code']);
            $this->assertCount(2, $document['body']['$permissions']);
            $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
            $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $document['body']['$permissions']);
        }

        // remove collection
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $moviesId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return [];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testAttributeBooleanDefault(array $data): void
    {
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Boolean'
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $collectionId = $collection['body']['$id'];

        $true = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'true',
            'required' => false,
            'default' => true
        ]);

        $this->assertEquals(202, $true['headers']['status-code']);

        $false = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'false',
            'required' => false,
            'default' => false
        ]);

        $this->assertEquals(202, $false['headers']['status-code']);
    }

    /**
     * @depends testCreateDatabase
     */
    public function testOneToOneRelationship(array $data): array
    {
        $databaseId = $data['databaseId'];

        $person = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'person',
            'name' => 'person',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $person['headers']['status-code']);

        $library = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'library',
            'name' => 'library',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $library['headers']['status-code']);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fullName',
            'size' => 255,
            'required' => false,
        ]);

        sleep(1); // Wait for worker

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => 'library',
            'type' => Database::RELATION_ONE_TO_ONE,
            'key' => 'library',
            'twoWay' => true,
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);

        sleep(1); // Wait for worker

        $libraryName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $library['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'libraryName',
            'size' => 255,
            'required' => true,
        ]);

        sleep(1); // Wait for worker

        $this->assertEquals(202, $libraryName['headers']['status-code']);
        $this->assertEquals(202, $relation['headers']['status-code']);
        $this->assertEquals('library', $relation['body']['key']);
        $this->assertEquals('relationship', $relation['body']['type']);
        $this->assertEquals('processing', $relation['body']['status']);

        $attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attributes['headers']['status-code']);
        $this->assertEquals(2, $attributes['body']['total']);
        $attributes = $attributes['body']['attributes'];
        $this->assertEquals('library', $attributes[1]['relatedCollection']);
        $this->assertEquals('oneToOne', $attributes[1]['relationType']);
        $this->assertEquals(true, $attributes[1]['twoWay']);
        $this->assertEquals('person', $attributes[1]['twoWayKey']);
        $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $attributes[1]['onDelete']);

        $attribute = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$person['body']['$id']}/attributes/library", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('available', $attribute['body']['status']);
        $this->assertEquals('library', $attribute['body']['key']);
        $this->assertEquals('relationship', $attribute['body']['type']);
        $this->assertEquals(false, $attribute['body']['required']);
        $this->assertEquals(false, $attribute['body']['array']);
        $this->assertEquals('oneToOne', $attribute['body']['relationType']);
        $this->assertEquals(true, $attribute['body']['twoWay']);
        $this->assertEquals('person', $attribute['body']['twoWayKey']);
        $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $attribute['body']['onDelete']);

        $person1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'library' => [
                    '$id' => 'library1',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'libraryName' => 'Library 1',
                ],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals('Library 1', $person1['body']['library']['libraryName']);

        // Create without nested ID
        $person2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'library' => [
                    'libraryName' => 'Library 2',
                ],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals('Library 2', $person2['body']['library']['libraryName']);

        // Ensure IDs were set and internal IDs removed
        $this->assertEquals($databaseId, $person1['body']['$databaseId']);
        $this->assertEquals($databaseId, $person1['body']['library']['$databaseId']);

        $this->assertEquals($person['body']['$id'], $person1['body']['$collectionId']);
        $this->assertEquals($library['body']['$id'], $person1['body']['library']['$collectionId']);

        $this->assertArrayNotHasKey('$collection', $person1['body']);
        $this->assertArrayNotHasKey('$collection', $person1['body']['library']);
        $this->assertArrayHasKey('$sequence', $person1['body']);
        $this->assertArrayHasKey('$sequence', $person1['body']['library']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString(),
                Query::equal('library', ['library1'])->toString(),
            ],
        ]);

        $this->assertEquals(1, $documents['body']['total']);
        $this->assertEquals('Library 1', $documents['body']['documents'][0]['library']['libraryName']);
        $this->assertArrayHasKey('fullName', $documents['body']['documents'][0]);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['library.*'])->toString(),
                Query::equal('library.libraryName', ['Library 1'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1, $documents['body']['total']);
        $this->assertCount(1, $documents['body']['documents']);
        $this->assertEquals('Library 1', $documents['body']['documents'][0]['library']['libraryName']);
        $this->assertEquals($person1['body']['$id'], $documents['body']['documents'][0]['$id']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/attributes/library', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        sleep(2);

        $this->assertEquals(204, $response['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$person['body']['$id']}/attributes/library", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(404, $attribute['headers']['status-code']);

        $person1 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $person1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertArrayNotHasKey('library', $person1['body']);

        //Test Deletion of related twoKey
        $attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $library['body']['$id'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attributes['headers']['status-code']);
        $this->assertEquals(1, $attributes['body']['total']);
        $this->assertEquals('libraryName', $attributes['body']['attributes'][0]['key']);

        return [
            'databaseId' => $databaseId,
            'personCollection' => $person['body']['$id'],
            'libraryCollection' => $library['body']['$id'],
        ];
    }

    /**
     * @depends testOneToOneRelationship
     */
    public function testOneToManyRelationship(array $data): array
    {
        $databaseId = $data['databaseId'];
        $personCollection = $data['personCollection'];
        $libraryCollection = $data['libraryCollection'];

        // One person can own several libraries
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $personCollection . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => 'library',
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'libraries',
            'twoWayKey' => 'person_one_to_many',
        ]);

        sleep(1);

        $libraryAttributesResponse = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $libraryCollection . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertIsArray($libraryAttributesResponse['body']['attributes']);
        $this->assertEquals(2, $libraryAttributesResponse['body']['total']);
        $this->assertEquals('person_one_to_many', $libraryAttributesResponse['body']['attributes'][1]['key']);

        $libraryCollectionResponse = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $libraryCollection, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertIsArray($libraryCollectionResponse['body']['attributes']);
        $this->assertCount(2, $libraryCollectionResponse['body']['attributes']);

        $attribute = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$personCollection}/attributes/libraries", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('available', $attribute['body']['status']);
        $this->assertEquals('libraries', $attribute['body']['key']);
        $this->assertEquals('relationship', $attribute['body']['type']);
        $this->assertEquals(false, $attribute['body']['required']);
        $this->assertEquals(false, $attribute['body']['array']);
        $this->assertEquals('oneToMany', $attribute['body']['relationType']);
        $this->assertEquals(true, $attribute['body']['twoWay']);
        $this->assertEquals('person_one_to_many', $attribute['body']['twoWayKey']);
        $this->assertEquals('restrict', $attribute['body']['onDelete']);

        $person2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $personCollection . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'person10',
            'data' => [
                'fullName' => 'Stevie Wonder',
                'libraries' => [
                    [
                        '$id' => 'library10',
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                        'libraryName' => 'Library 10',
                    ],
                    [
                        '$id' => 'library11',
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                        'libraryName' => 'Library 11',
                    ]
                ],
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $person2['headers']['status-code']);
        $this->assertArrayHasKey('libraries', $person2['body']);
        $this->assertEquals(2, count($person2['body']['libraries']));

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $personCollection . '/documents/' . $person2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*', 'libraries.*'])->toString()
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('$collection', $response['body']);
        $this->assertArrayHasKey('libraries', $response['body']);
        $this->assertEquals(2, count($response['body']['libraries']));

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $libraryCollection . '/documents/library11', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['person_one_to_many.$id'])->toString()
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('person_one_to_many', $response['body']);
        $this->assertEquals('person10', $response['body']['person_one_to_many']['$id']);

        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $personCollection . '/attributes/libraries/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$personCollection}/attributes/libraries", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals('available', $attribute['body']['status']);
        $this->assertEquals('libraries', $attribute['body']['key']);
        $this->assertEquals('relationship', $attribute['body']['type']);
        $this->assertEquals(false, $attribute['body']['required']);
        $this->assertEquals(false, $attribute['body']['array']);
        $this->assertEquals('oneToMany', $attribute['body']['relationType']);
        $this->assertEquals(true, $attribute['body']['twoWay']);
        $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $attribute['body']['onDelete']);

        return ['databaseId' => $databaseId, 'personCollection' => $personCollection];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testManyToOneRelationship(array $data): array
    {
        $databaseId = $data['databaseId'];

        // Create album collection
        $albums = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Albums',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create album name attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $albums['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create artist collection
        $artists = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Artists',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create artist name attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $artists['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create relationship
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $albums['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $artists['body']['$id'],
            'type' => Database::RELATION_MANY_TO_ONE,
            'twoWay' => true,
            'key' => 'artist',
            'twoWayKey' => 'albums',
        ]);
        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertEquals('artist', $response['body']['key']);
        $this->assertEquals('relationship', $response['body']['type']);
        $this->assertEquals(false, $response['body']['required']);
        $this->assertEquals(false, $response['body']['array']);
        $this->assertEquals('manyToOne', $response['body']['relationType']);
        $this->assertEquals(true, $response['body']['twoWay']);
        $this->assertEquals('albums', $response['body']['twoWayKey']);
        $this->assertEquals('restrict', $response['body']['onDelete']);

        sleep(1); // Wait for worker

        $permissions = [
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
            Permission::delete(Role::user($this->getUser()['$id'])),
        ];

        // Create album
        $album = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $albums['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'album1',
            'permissions' => $permissions,
            'data' => [
                'name' => 'Album 1',
                'artist' => [
                    '$id' => ID::unique(),
                    'name' => 'Artist 1',
                ],
            ],
        ]);

        $this->assertEquals(201, $album['headers']['status-code']);
        $this->assertEquals('album1', $album['body']['$id']);
        $this->assertEquals('Album 1', $album['body']['name']);
        $this->assertEquals('Artist 1', $album['body']['artist']['name']);
        $this->assertEquals($permissions, $album['body']['$permissions']);
        $this->assertEquals($permissions, $album['body']['artist']['$permissions']);

        $album = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $albums['body']['$id'] . '/documents/album1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*', 'artist.name', 'artist.$permissions'])->toString()
            ]
        ]);

        $this->assertEquals(200, $album['headers']['status-code']);
        $this->assertEquals('album1', $album['body']['$id']);
        $this->assertEquals('Album 1', $album['body']['name']);
        $this->assertEquals('Artist 1', $album['body']['artist']['name']);
        $this->assertEquals($permissions, $album['body']['$permissions']);
        $this->assertEquals($permissions, $album['body']['artist']['$permissions']);

        $artist = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $artists['body']['$id'] . '/documents/' . $album['body']['artist']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*', 'albums.$id', 'albums.name', 'albums.$permissions'])->toString()
            ]
        ]);

        $this->assertEquals(200, $artist['headers']['status-code']);
        $this->assertEquals('Artist 1', $artist['body']['name']);
        $this->assertEquals($permissions, $artist['body']['$permissions']);
        $this->assertEquals(1, count($artist['body']['albums']));
        $this->assertEquals('album1', $artist['body']['albums'][0]['$id']);
        $this->assertEquals('Album 1', $artist['body']['albums'][0]['name']);
        $this->assertEquals($permissions, $artist['body']['albums'][0]['$permissions']);

        return [
            'databaseId' => $databaseId,
            'albumsCollection' => $albums['body']['$id'],
            'artistsCollection' => $artists['body']['$id'],
        ];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testManyToManyRelationship(array $data): array
    {
        $databaseId = $data['databaseId'];

        // Create sports collection
        $sports = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Sports',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create sport name attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $sports['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create player collection
        $players = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Players',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create player name attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $players['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create relationship
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $sports['body']['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $players['body']['$id'],
            'type' => Database::RELATION_MANY_TO_MANY,
            'twoWay' => true,
            'key' => 'players',
            'twoWayKey' => 'sports',
            'onDelete' => Database::RELATION_MUTATE_SET_NULL,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertEquals('players', $response['body']['key']);
        $this->assertEquals('relationship', $response['body']['type']);
        $this->assertEquals(false, $response['body']['required']);
        $this->assertEquals(false, $response['body']['array']);
        $this->assertEquals('manyToMany', $response['body']['relationType']);
        $this->assertEquals(true, $response['body']['twoWay']);
        $this->assertEquals('sports', $response['body']['twoWayKey']);
        $this->assertEquals('setNull', $response['body']['onDelete']);

        sleep(1); // Wait for worker

        $permissions = [
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
        ];

        // Create sport
        $sport = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $sports['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'sport1',
            'permissions' => $permissions,
            'data' => [
                'name' => 'Sport 1',
                'players' => [
                    [
                        '$id' => 'player1',
                        'name' => 'Player 1',
                    ],
                    [
                        '$id' => 'player2',
                        'name' => 'Player 2',
                    ]
                ],
            ],
        ]);

        $this->assertEquals(201, $sport['headers']['status-code']);
        $this->assertEquals('sport1', $sport['body']['$id']);
        $this->assertEquals('Sport 1', $sport['body']['name']);
        $this->assertEquals('Player 1', $sport['body']['players'][0]['name']);
        $this->assertEquals('Player 2', $sport['body']['players'][1]['name']);
        $this->assertEquals($permissions, $sport['body']['$permissions']);
        $this->assertEquals($permissions, $sport['body']['players'][0]['$permissions']);
        $this->assertEquals($permissions, $sport['body']['players'][1]['$permissions']);

        $sport = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $sports['body']['$id'] . '/documents/sport1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*', 'players.name', 'players.$permissions'])->toString()
            ]
        ]);

        $this->assertEquals(200, $sport['headers']['status-code']);
        $this->assertEquals('sport1', $sport['body']['$id']);
        $this->assertEquals('Sport 1', $sport['body']['name']);
        $this->assertEquals('Player 1', $sport['body']['players'][0]['name']);
        $this->assertEquals('Player 2', $sport['body']['players'][1]['name']);
        $this->assertEquals($permissions, $sport['body']['$permissions']);
        $this->assertEquals($permissions, $sport['body']['players'][0]['$permissions']);
        $this->assertEquals($permissions, $sport['body']['players'][1]['$permissions']);

        $player = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $players['body']['$id'] . '/documents/' . $sport['body']['players'][0]['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*', 'sports.$id', 'sports.name', 'sports.$permissions'])->toString()
            ]
        ]);

        $this->assertEquals(200, $player['headers']['status-code']);
        $this->assertEquals('Player 1', $player['body']['name']);
        $this->assertEquals($permissions, $player['body']['$permissions']);
        $this->assertEquals(1, count($player['body']['sports']));
        $this->assertEquals('sport1', $player['body']['sports'][0]['$id']);
        $this->assertEquals('Sport 1', $player['body']['sports'][0]['name']);
        $this->assertEquals($permissions, $player['body']['sports'][0]['$permissions']);

        return [
            'databaseId' => $databaseId,
            'sportsCollection' => $sports['body']['$id'],
            'playersCollection' => $players['body']['$id'],
        ];
    }

    /**
     * @depends testOneToManyRelationship
     */
    public function testValidateOperators(array $data): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['personCollection'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::isNotNull('$id')->toString(),
                Query::select(['*', 'libraries.*'])->toString(),
                Query::startsWith('fullName', 'Stevie')->toString(),
                Query::endsWith('fullName', 'Wonder')->toString(),
                Query::between('$createdAt', '1975-12-06', '2050-12-01')->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, count($response['body']['documents']));
        $this->assertEquals('person10', $response['body']['documents'][0]['$id']);
        $this->assertEquals('Stevie Wonder', $response['body']['documents'][0]['fullName']);
        $this->assertEquals(2, count($response['body']['documents'][0]['libraries']));

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['personCollection'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::isNotNull('$id')->toString(),
                Query::isNull('fullName')->toString(),
                Query::select(['fullName'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, count($response['body']['documents']));
        $this->assertEquals(null, $response['body']['documents'][0]['fullName']);
        $this->assertArrayNotHasKey("libraries", $response['body']['documents'][0]);
        $this->assertArrayHasKey('$databaseId', $response['body']['documents'][0]);
        $this->assertArrayHasKey('$collectionId', $response['body']['documents'][0]);
    }

    /**
     * @depends testOneToManyRelationship
     */
    public function testSelectQueries(array $data): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['personCollection'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('fullName', ['Stevie Wonder'])->toString(),
                Query::select(['fullName'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('libraries', $response['body']['documents'][0]);
        $this->assertArrayHasKey('$databaseId', $response['body']['documents'][0]);
        $this->assertArrayHasKey('$collectionId', $response['body']['documents'][0]);

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['personCollection'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['libraries.*', '$id'])->toString(),
            ],
        ]);
        $document = $response['body']['documents'][0];
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('libraries', $document);
        $this->assertArrayHasKey('$databaseId', $document);
        $this->assertArrayHasKey('$collectionId', $document);

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['personCollection'] . '/documents/' . $document['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', '$id'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('fullName', $response['body']);
        $this->assertArrayNotHasKey('libraries', $response['body']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testOrQueries(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Or queries'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Or queries', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $presidents = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'USA Presidents',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $presidents['headers']['status-code']);
        $this->assertEquals($presidents['body']['name'], 'USA Presidents');

        // Create Attributes
        $firstName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'first_name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $firstName['headers']['status-code']);

        $lastName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'last_name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $lastName['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'first_name' => 'Donald',
                'last_name' => 'Trump',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $document1['headers']['status-code']);

        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'first_name' => 'George',
                'last_name' => 'Bush',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $document2['headers']['status-code']);

        $document3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'first_name' => 'Joe',
                'last_name' => 'Biden',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $document3['headers']['status-code']);

        $documents = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::select(['first_name', 'last_name'])->toString(),
                    Query::or([
                        Query::equal('first_name', ['Donald']),
                        Query::equal('last_name', ['Bush'])
                    ])->toString(),
                    Query::limit(999)->toString(),
                    Query::offset(0)->toString()
                ],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertCount(2, $documents['body']['documents']);
    }

    /**
     * @depends testCreateDatabase
     * @param array $data
     * @return void
     * @throws \Exception
     */
    public function testUpdateWithExistingRelationships(array $data): void
    {
        $databaseId = $data['databaseId'];

        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Collection1',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Collection2',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collection1 = $collection1['body']['$id'];
        $collection2 = $collection2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1 . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => '49',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection2 . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => '49',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1 . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2,
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'collection2'
        ]);

        sleep(1);

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1 . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Document 1',
                'collection2' => [
                    [
                        'name' => 'Document 2',
                    ],
                ],
            ],
        ]);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1 . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Document 1 Updated',
            ],
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
    }

    /**
     * @depends testCreateDatabase
     */
    public function testTimeout(array $data): void
    {
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Slow Queries',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $data = [
            '$id' => $collection['body']['$id'],
            'databaseId' => $collection['body']['databaseId']
        ];

        $longtext = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'longtext',
            'size' => 100000000,
            'required' => false,
            'default' => null,
        ]);

        $this->assertEquals($longtext['headers']['status-code'], 202);

        for ($i = 0; $i < 10; $i++) {
            $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documentId' => ID::unique(),
                'data' => [
                    'longtext' => file_get_contents(__DIR__ . '../../../../../resources/longtext.txt'),
                ],
                'permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ]
            ]);
        }

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timeout' => 1,
        ], $this->getHeaders()), [
            'queries' => [
                Query::notEqual('longtext', 'appwrite')->toString(),
            ],
        ]);

        $this->assertEquals(408, $response['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    /**
     * @throws \Exception
     */
    public function testIncrementAttribute(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CounterDatabase'
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'CounterCollection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $collectionId = $collection['body']['$id'];

        // Add integer attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'count',
            'required' => true,
        ]);

        \sleep(3);

        // Create document with initial count = 5
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'count' => 5
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $doc['headers']['status-code']);

        $docId = $doc['body']['$id'];

        // Increment by default 1
        $inc = $this->client->call(Client::METHOD_PATCH, "/databases/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(200, $inc['headers']['status-code']);
        $this->assertEquals(6, $inc['body']['count']);
        $this->assertEquals($collectionId, $inc['body']['$collectionId']);

        // Verify count = 6
        $get = $this->client->call(Client::METHOD_GET, "/databases/$databaseId/collections/$collectionId/documents/$docId", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(6, $get['body']['count']);

        // Increment by custom value 4
        $inc2 = $this->client->call(Client::METHOD_PATCH, "/databases/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 4
        ]);
        $this->assertEquals(200, $inc2['headers']['status-code']);
        $this->assertEquals(10, $inc2['body']['count']);

        $get2 = $this->client->call(Client::METHOD_GET, "/databases/$databaseId/collections/$collectionId/documents/$docId", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(10, $get2['body']['count']);

        // Test max limit exceeded
        $err = $this->client->call(Client::METHOD_PATCH, "/databases/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['max' => 8]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test attribute not found
        $notFound = $this->client->call(Client::METHOD_PATCH, "/databases/$databaseId/collections/$collectionId/documents/$docId/unknown/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(404, $notFound['headers']['status-code']);

        // Test increment with value 0
        $inc3 = $this->client->call(Client::METHOD_PATCH, "/databases/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 0
        ]);
        $this->assertEquals(400, $inc3['headers']['status-code']);
    }

    public function testDecrementAttribute(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CounterDatabase'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'CounterCollection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Add integer attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'count',
            'required' => true,
        ]);

        \sleep(2);

        // Create document with initial count = 10
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => ['count' => 10],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $documentId = $doc['body']['$id'];

        // Decrement by default 1 (count = 10 -> 9)
        $dec = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(200, $dec['headers']['status-code']);
        $this->assertEquals(9, $dec['body']['count']);
        $this->assertEquals($collectionId, $dec['body']['$collectionId']);

        $get = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(9, $get['body']['count']);

        // Decrement by custom value 3 (count 9 -> 6)
        $dec2 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 3
        ]);
        $this->assertEquals(200, $dec2['headers']['status-code']);
        $this->assertEquals(6, $dec2['body']['count']);

        $get2 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(6, $get2['body']['count']);

        // Test min limit exceeded
        $err = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['min' => 7]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test min limit exceeded with custom value
        $err = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 3,
            'min' => 5,
        ]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test min limit 0
        $err = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 10,
            'min' => 0,
        ]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test type error on non-numeric attribute
        $typeErr = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['value' => 'not-a-number']);
        $this->assertEquals(400, $typeErr['headers']['status-code']);

        // Test decrement with value 0
        $inc3 = $this->client->call(Client::METHOD_PATCH, "/databases/$databaseId/collections/$collectionId/documents/$documentId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 0
        ]);
        $this->assertEquals(400, $inc3['headers']['status-code']);
    }

    public function testSpatialPointAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Point Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Point Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        // Create point attribute - handle both 201 (created) and 200 (already exists)
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'location',
            'required' => true,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(2);

        // Test 1: Create document with point attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Test Location',
                'location' => [40.7128, -74.0060] // New York coordinates
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([40.7128, -74.0060], $response['body']['location']);
        $documentId = $response['body']['$id'];

        // Test 2: Read document with point attribute
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7128, -74.0060], $response['body']['location']);

        // Test 3: Update document with new point coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'location' => [40.7589, -73.9851] // Times Square coordinates
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7589, -73.9851], $response['body']['location']);

        // Test 4: Upsert document with point attribute
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Upserted Location',
                'location' => [34.0522, -80] // Los Angeles coordinates
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([34.0522, -80], $response['body']['location']);

        // Test 5: Create document without permissions (should fail)
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Unauthorized Location',
                'location' => [0, 0]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialLineAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Line Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Line Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create integer attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'distance',
            'required' => true,
        ]);

        // Create line attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => true,
        ]);

        sleep(2);

        // Test 1: Create document with line attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'distance' => 100,
                'route' => [[40.7128, -74.0060], [40.7589, -73.9851]] // Line from Downtown to Times Square
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851]], $response['body']['route']);
        $documentId = $response['body']['$id'];

        // Test 2: Read document with line attribute
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851]], $response['body']['route']);

        // Test 3: Update document with new line coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'route' => [[40.7128, -74.0060], [40.7589, -73.9851], [40.7505, -73.9934]] // Extended route
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851], [40.7505, -73.9934]], $response['body']['route']);

        // Test 4: Upsert document with line attribute
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'distance' => 200,
                'route' => [[34.0522, -80], [34.0736, -90]] // LA route
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[34.0522, -80], [34.0736, -90]], $response['body']['route']);

        // Test 5: Delete document
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Test 6: Verify document is deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialPolygonAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Polygon Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Polygon Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create boolean attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'active',
            'required' => true,
        ]);

        // Create polygon attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        sleep(2);

        // Test 1: Create document with polygon attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'active' => true,
                'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]] // Manhattan area
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $response['body']['area']);
        $documentId = $response['body']['$id'];

        // Test 2: Read document with polygon attribute
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $response['body']['area']);

        // Test 3: Update document with new polygon coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7505, -73.9934], [40.7128, -74.0060]]] // Extended area
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7505, -73.9934], [40.7128, -74.0060]]], $response['body']['area']);

        // Test 4: Upsert document with polygon attribute
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'active' => false,
                'area' => [[[34.0522, -80], [34.0736, -80], [34.0736, -90], [34.0522, -90], [34.0522, -80]]] // LA area
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[34.0522, -80], [34.0736, -80], [34.0736, -90], [34.0522, -90], [34.0522, -80]]], $response['body']['area']);

        // Test 5: Create document without required polygon attribute (should fail)
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'active' => true
                // Missing required 'area' attribute
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialAttributesMixedCollection(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Mixed Spatial Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with multiple spatial and non-spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Mixed Spatial Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create multiple attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'center',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boundary',
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'coverage',
            'required' => true,
        ]);

        sleep(3);

        // Test 1: Create document with all spatial attributes
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Central Park',
                'center' => [40.7829, -73.9654],
                'boundary' => [[40.7649, -73.9814], [40.8009, -73.9494]],
                'coverage' => [[[40.7649, -73.9814], [40.8009, -73.9814], [40.8009, -73.9494], [40.7649, -73.9494], [40.7649, -73.9814]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([40.7829, -73.9654], $response['body']['center']);
        $this->assertEquals([[40.7649, -73.9814], [40.8009, -73.9494]], $response['body']['boundary']);
        $this->assertEquals([[[40.7649, -73.9814], [40.8009, -73.9814], [40.8009, -73.9494], [40.7649, -73.9494], [40.7649, -73.9814]]], $response['body']['coverage']);
        $documentId = $response['body']['$id'];

        // Test 2: Update document with new spatial data
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'center' => [40.7505, -73.9934],
                'boundary' => [[40.7305, -74.0134], [40.7705, -73.9734]],
                'coverage' => [[[40.7305, -74.0134], [40.7705, -74.0134], [40.7705, -73.9734], [40.7305, -73.9734], [40.7305, -74.0134]]]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7505, -73.9934], $response['body']['center']);
        $this->assertEquals([[40.7305, -74.0134], [40.7705, -73.9734]], $response['body']['boundary']);
        $this->assertEquals([[[40.7305, -74.0134], [40.7705, -74.0134], [40.7705, -73.9734], [40.7305, -73.9734], [40.7305, -74.0134]]], $response['body']['coverage']);

        // Test 3: Create document with minimal required attributes
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Minimal Location',
                'center' => [0, 0],
                'coverage' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['center']);

        // Test 4: Test permission validation - create without user context
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Unauthorized Location',
                'center' => [0, 0],
                'coverage' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testUpdateSpatialAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Update Spatial Attributes Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Update Spatial Attributes Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        // Create point attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'location',
            'required' => true,
        ]);

        // Create line attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => false,
        ]);

        // Create polygon attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        sleep(2);

        // Test 1: Update point attribute - change required status
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point/location', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['required']);

        // Test 2: Update line attribute - change required status and add default value
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line/route', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => [[0, 0], [1, 1]],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['required']);
        $this->assertEquals([[0, 0], [1, 1]], $response['body']['default']);

        // Test 3: Update polygon attribute - change key name
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon/area', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'coverage',
            'default' => null,
            'required' => false
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('coverage', $response['body']['key']);

        // Test 4: Update point attribute - add default value
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point/location', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => [0, 0],
            'required' => false
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['default']);

        // Test 5: Verify attribute updates by creating a document
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Test Location',
                'coverage' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['location']); // Should use default value
        $this->assertEquals([[0, 0], [1, 1]], $response['body']['route']); // Should use default value

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialQuery(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Query Test Database'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $databaseId = $database['body']['$id'];

        // Create collection with spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Query Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $nameAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $nameAttribute['headers']['status-code']);

        // Create point attribute
        $pointAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'pointAttr',
            'required' => true,
        ]);

        $this->assertEquals(202, $pointAttribute['headers']['status-code']);

        // Create line attribute
        $lineAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lineAttr',
            'required' => true,
        ]);

        $this->assertEquals(202, $lineAttribute['headers']['status-code']);

        // Create polygon attribute
        $polygonAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'polyAttr',
            'required' => true,
        ]);

        $this->assertEquals(202, $polygonAttribute['headers']['status-code']);

        // Wait for attributes to be created
        sleep(2);

        // Create test documents with spatial data
        $documents = [
            [
                '$id' => 'doc1',
                'name' => 'Test Document 1',
                'pointAttr' => [6.0, 6.0],
                'lineAttr' => [[1.0, 1.0], [1.1,1.1] , [2.0, 2.0]],
                'polyAttr' => [[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]]]
            ],
            [
                '$id' => 'doc2',
                'name' => 'Test Document 2',
                'pointAttr' => [7.0, 6.0],
                'lineAttr' => [[10.0, 10.0], [20.0, 20.0]],
                'polyAttr' => [[[20.0, 20.0], [30.0, 20.0], [30.0, 30.0], [20.0, 30.0], [20.0, 20.0]]]
            ],
            [
                '$id' => 'doc3',
                'name' => 'Test Document 3',
                'pointAttr' => [25.0, 25.0],
                'lineAttr' => [[25.0, 25.0], [35.0, 35.0]],
                'polyAttr' => [[[40.0, 40.0], [50.0, 40.0], [50.0, 50.0], [40.0, 50.0], [40.0, 40.0]]]
            ]
        ];

        foreach ($documents as $doc) {
            $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documentId' => $doc['$id'],
                'data' => [
                    'name' => $doc['name'],
                    'pointAttr' => $doc['pointAttr'],
                    'lineAttr' => $doc['lineAttr'],
                    'polyAttr' => $doc['polyAttr']
                ]
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);
        }

        // Test 1: Equality on non-spatial attribute (name)
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('name', ['Test Document 1'])->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['documents']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);

        // Test 3: Polygon attribute queries
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('polyAttr', [[[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]]]])->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['documents']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);

        // Test 4: Not equal queries
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notEqual('pointAttr', [[6.0, 6.0]])->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['documents']);

        // Test 4.1: contains on line (point on line)
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::contains('lineAttr', [[1.1, 1.1]])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['documents']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);


        // Test 4.2: notContains on polygon (point outside all polygons)
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notContains('polyAttr', [[15.0, 15.0]])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // Test 4.3: intersects on polygon (point inside doc1 polygon)
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::intersects('polyAttr', [5.0, 5.0])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);

        // Test 4.4: notIntersects on polygon (point outside all polygons)
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notIntersects('polyAttr', [60.0, 60.0])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // Test 4.5: overlaps on polygon (polygon overlapping doc1)
        $overlapPoly = [[[5.0, 5.0], [12.0, 5.0], [12.0, 12.0], [5.0, 12.0], [5.0, 5.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::overlaps('polyAttr', $overlapPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);

        // Test 4.6: notOverlaps on polygon (polygon that overlaps none)
        $noOverlapPoly = [[[60.0, 60.0], [70.0, 60.0], [70.0, 70.0], [60.0, 70.0], [60.0, 60.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notOverlaps('polyAttr', $noOverlapPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // Test 4.7: distance (equals) on point
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceEqual('pointAttr', [6.0, 6.0], 1.0)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('doc2', $response['body']['documents'][0]['$id']);

        // Test 4.8: notDistance (outside radius) on point
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceNotEqual('pointAttr', [6.0, 6.0], 1.0)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        // Test 4.9: distanceGreaterThan
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceGreaterThan('pointAttr', [6.0, 6.0], 5.0)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        // Test 4.10: distanceLessThan
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceLessThan('pointAttr', [6.0, 6.0], 0.5)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        // Test 4.11: crosses on line (query line crosses doc1 line)
        $crossLine = [[1.0, 2.0], [2.0, 1.0]];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::crosses('lineAttr', $crossLine)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);

        // Test 4.12: notCrosses on line (query line does not cross any stored lines)
        $nonCrossLine = [[0.0, 1.0], [0.0, 2.0]];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notCrosses('lineAttr', $nonCrossLine)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // Test 4.13: touches on polygon (query polygon touches doc1 polygon at corner)
        $touchPoly = [[[10.0, 10.0], [20.0, 10.0], [20.0, 20.0], [10.0, 20.0], [10.0, 10.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::touches('polyAttr', $touchPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals('doc1', $response['body']['documents'][0]['$id']);

        // Test 4.14: notTouches on polygon (polygon far away should not touch)
        $farPoly = [[[60.0, 60.0], [70.0, 60.0], [70.0, 70.0], [60.0, 70.0], [60.0, 60.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notTouches('polyAttr', $farPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // Test 5: Select specific attributes
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::select(['name', 'pointAttr'])->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['documents']);

        foreach ($response['body']['documents'] as $doc) {
            $this->assertArrayHasKey('name', $doc);
            $this->assertArrayHasKey('pointAttr', $doc);
            $this->assertArrayNotHasKey('lineAttr', $doc);
            $this->assertArrayNotHasKey('polyAttr', $doc);
        }

        // Test 6: Order by name
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::orderAsc('name')->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['documents']);
        $this->assertEquals('Test Document 1', $response['body']['documents'][0]['name']);
        $this->assertEquals('Test Document 2', $response['body']['documents'][1]['name']);
        $this->assertEquals('Test Document 3', $response['body']['documents'][2]['name']);

        // Test 7: Limit results
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(2)->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['documents']);

        // Test 8: Offset results
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::offset(1)->toString(), Query::limit(2)->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['documents']);

        // Test 9: Complex query with multiple conditions
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['name', 'pointAttr'])->toString(),
                Query::orderAsc('name')->toString(),
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['documents']);
        $this->assertEquals('Test Document 1', $response['body']['documents'][0]['name']);

        // Test 11: Query with no results
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('name', ['Non-existent Document'])->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['documents']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialRelationshipOneToOne(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial OneToOne Test DB'
        ]);

        $databaseId = $database['body']['$id'];

        $place = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Place',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $placeId = $place['body']['$id'];

        $location = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Location',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $locationId = $location['body']['$id'];

        // attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $placeId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $locationId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'coordinates',
            'required' => true,
        ]);

        sleep(2);

        // relationship: place.oneToOne -> location
        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $placeId . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $locationId,
            'type' => Database::RELATION_ONE_TO_ONE,
            'key' => 'location',
            'twoWay' => true,
            'twoWayKey' => 'place',
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);
        $this->assertEquals(202, $relation['headers']['status-code']);

        sleep(2);

        // create doc with nested spatial related doc
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $placeId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Museum',
                'location' => [
                    '$id' => ID::unique(),
                    'coordinates' => [40.7794, -73.9632],
                ],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $doc['headers']['status-code']);
        $this->assertEquals([40.7794, -73.9632], $doc['body']['location']['coordinates']);

        // fetch with select to ensure relationship shape
        $fetched = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $placeId . '/documents/' . $doc['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['name', 'location.coordinates'])->toString()
            ]
        ]);
        $this->assertEquals(200, $fetched['headers']['status-code']);
        $this->assertEquals([40.7794, -73.9632], $fetched['body']['location']['coordinates']);

        // cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $placeId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $locationId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialRelationshipOneToMany(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial OneToMany Test DB'
        ]);
        $databaseId = $database['body']['$id'];

        $person = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Person',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $personId = $person['body']['$id'];

        $visit = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Visit',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $visitId = $visit['body']['$id'];

        // attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $personId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fullName',
            'size' => 255,
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $visitId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'point',
            'required' => true,
        ]);

        sleep(2);

        // relationship person.oneToMany -> visit
        $rel = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $personId . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $visitId,
            'type' => Database::RELATION_ONE_TO_MANY,
            'key' => 'visits',
            'twoWay' => true,
            'twoWayKey' => 'person',
        ]);
        $this->assertEquals(202, $rel['headers']['status-code']);

        sleep(2);

        $personDoc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $personId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'person-spatial-1',
            'data' => [
                'fullName' => 'Alice',
                'visits' => [
                    [ '$id' => 'visit-1', 'point' => [40.7589, -73.9851] ],
                    [ '$id' => 'visit-2', 'point' => [40.7505, -73.9934] ],
                ],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $personDoc['headers']['status-code']);
        $this->assertCount(2, $personDoc['body']['visits']);
        $this->assertEquals([40.7589, -73.9851], $personDoc['body']['visits'][0]['point']);

        $visitDoc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $visitId . '/documents/visit-2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['point', 'person.$id'])->toString()
            ]
        ]);
        $this->assertEquals(200, $visitDoc['headers']['status-code']);
        $this->assertEquals('person-spatial-1', $visitDoc['body']['person']['$id']);

        // cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $personId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $visitId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialRelationshipManyToOne(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial ManyToOne Test DB'
        ]);
        $databaseId = $database['body']['$id'];

        $cities = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'City',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $citiesId = $cities['body']['$id'];

        $stores = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Store',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $storesId = $stores['body']['$id'];

        // attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $citiesId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $storesId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        sleep(2);

        // relationship stores.manyToOne -> cities
        $rel = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $storesId . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $citiesId,
            'type' => Database::RELATION_MANY_TO_ONE,
            'key' => 'city',
            'twoWay' => true,
            'twoWayKey' => 'stores',
        ]);
        $this->assertEquals(202, $rel['headers']['status-code']);

        sleep(2);

        $store = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $storesId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'store-1',
            'data' => [
                'name' => 'Main Store',
                'city' => [
                    '$id' => ID::unique(),
                    'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]]
                ],
            ]
        ]);
        $this->assertEquals(201, $store['headers']['status-code']);
        $this->assertEquals('Main Store', $store['body']['name']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $store['body']['city']['area']);

        $city = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $citiesId . '/documents/' . $store['body']['city']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['stores.$id'])->toString()
            ]
        ]);
        $this->assertEquals(200, $city['headers']['status-code']);
        $this->assertEquals('store-1', $city['body']['stores'][0]['$id']);

        // cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $storesId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $citiesId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialRelationshipManyToMany(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial ManyToMany Test DB'
        ]);
        $databaseId = $database['body']['$id'];

        $drivers = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Drivers',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $driversId = $drivers['body']['$id'];

        $zones = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Zones',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $zonesId = $zones['body']['$id'];

        // attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $driversId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'home',
            'required' => true,
        ]);
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $zonesId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        sleep(2);

        // relationship drivers.manyToMany <-> zones
        $rel = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $driversId . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $zonesId,
            'type' => Database::RELATION_MANY_TO_MANY,
            'key' => 'zones',
            'twoWay' => true,
            'twoWayKey' => 'drivers',
        ]);
        $this->assertEquals(202, $rel['headers']['status-code']);

        sleep(2);

        // create driver with two zones containing spatial polygons
        $driver = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $driversId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'driver-1',
            'data' => [
                'home' => [40.7128, -74.0060],
                'zones' => [
                    [ '$id' => 'zone-1', 'area' => [[[0,0],[10,0],[10,10],[0,10],[0,0]]]],
                    [ '$id' => 'zone-2', 'area' => [[[20,20],[30,20],[30,30],[20,30],[20,20]]]],
                ],
            ]
        ]);
        $this->assertEquals(201, $driver['headers']['status-code']);
        $this->assertCount(2, $driver['body']['zones']);
        $this->assertEquals([40.7128, -74.0060], $driver['body']['home']);

        $zone = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $zonesId . '/documents/zone-1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['drivers.$id'])->toString()
            ]
        ]);
        $this->assertEquals(200, $zone['headers']['status-code']);
        $this->assertEquals('driver-1', $zone['body']['drivers'][0]['$id']);

        // cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $driversId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $zonesId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialIndex(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Index Test DB'
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'SpatialIdx',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create spatial attributes: one required, one optional
        $reqPoint = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'pRequired',
            'required' => true,
        ]);
        $this->assertEquals(202, $reqPoint['headers']['status-code']);

        $optPoint = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'pOptional',
            'required' => false,
        ]);
        $this->assertEquals(202, $optPoint['headers']['status-code']);

        // Ensure attributes are available
        sleep(2);

        // Create index on required spatial attribute (should succeed)
        $okIndex = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_required_point',
            'type' => Database::INDEX_SPATIAL,
            'attributes' => ['pRequired'],
        ]);
        $this->assertEquals(202, $okIndex['headers']['status-code']);

        // Create index on optional spatial attribute (should fail in case of mariadb)
        $badIndex = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_optional_point',
            'type' => Database::INDEX_SPATIAL,
            'attributes' => ['pOptional'],
        ]);
        $this->assertEquals(400, $badIndex['headers']['status-code']);

        // updating the attribute to required to create index
        $updated = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point/'.'pOptional', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => null
        ]);
        $this->assertEquals(200, $updated['headers']['status-code']);

        sleep(2);
        $retriedIndex = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_optional_point',
            'type' => Database::INDEX_SPATIAL,
            'attributes' => ['pOptional'],
        ]);
        $this->assertEquals(202, $retriedIndex['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialDistanceInMeter(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Distance Meters Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection with spatial attribute
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Distance Meters Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $collection['body']['$id'];

        // Create point attribute
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'loc',
            'required' => true,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);
        sleep(2);

        // Create spatial index
        $indexResponse = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_loc',
            'type' => Database::INDEX_SPATIAL,
            'attributes' => ['loc'],
        ]);

        sleep(2);
        $this->assertEquals(202, $indexResponse['headers']['status-code']);


        // Two points roughly ~1000 meters apart by latitude delta (~0.009 deg ≈ 1km)
        $p0 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'p0',
            'data' => [
                'loc' => [0.0000, 0.0000]
            ]
        ]);

        $p1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'p1',
            'data' => [
                'loc' => [0.0090, 0.0000]
            ]
        ]);

        $this->assertEquals(201, $p0['headers']['status-code']);
        $this->assertEquals(201, $p1['headers']['status-code']);

        // distanceLessThan with meters=true: within 1500m should include both
        $within1_5km = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceLessThan('loc', [0.0000, 0.0000], 1500, true)->toString()]
        ]);

        $this->assertEquals(200, $within1_5km['headers']['status-code']);
        $this->assertCount(2, $within1_5km['body']['documents']);

        // Within 500m should include only p0 (exact point)
        $within500m = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceLessThan('loc', [0.0000, 0.0000], 500, true)->toString()]
        ]);

        $this->assertEquals(200, $within500m['headers']['status-code']);
        $this->assertCount(1, $within500m['body']['documents']);
        $this->assertEquals('p0', $within500m['body']['documents'][0]['$id']);

        // distanceGreaterThan 500m should include only p1
        $greater500m = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceGreaterThan('loc', [0.0000, 0.0000], 500, true)->toString()]
        ]);

        $this->assertEquals(200, $greater500m['headers']['status-code']);
        $this->assertCount(1, $greater500m['body']['documents']);
        $this->assertEquals('p1', $greater500m['body']['documents'][0]['$id']);

        // distanceEqual with 0m should return exact match p0
        $equalZero = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceEqual('loc', [0.0000, 0.0000], 0, true)->toString()]
        ]);

        $this->assertEquals(200, $equalZero['headers']['status-code']);
        $this->assertEquals('p0', $equalZero['body']['documents'][0]['$id']);

        // distanceNotEqual with 0m should return p1
        $notEqualZero = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceNotEqual('loc', [0.0000, 0.0000], 0, true)->toString()]
        ]);

        $this->assertEquals(200, $notEqualZero['headers']['status-code']);
        $this->assertEquals('p1', $notEqualZero['body']['documents'][0]['$id']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialColCreateOnExistingData(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Distance Meters Database'
        ]);

        $databaseId = $database['body']['$id'];

        $colId = ID::unique();
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => $colId,
            'name' => 'spatial-test',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $description = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $this->assertEquals(202, $description['headers']['status-code']);
        sleep(2);

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'description' => 'description'
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);

        $point = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'loc',
            'required' => true,
        ]);

        $this->assertEquals(400, $point['headers']['status-code']);

        $point = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'loc',
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(202, $point['headers']['status-code']);

        $line = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => true,
        ]);

        $this->assertEquals(400, $line['headers']['status-code']);

        $line = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(202, $line['headers']['status-code']);

        $poly = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        $this->assertEquals(400, $poly['headers']['status-code']);

        $poly = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(202, $poly['headers']['status-code']);
    }

    public function testSpatialColCreateOnExistingDataWithDefaults(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial With Defaults Database'
        ]);

        $databaseId = $database['body']['$id'];

        $colId = ID::unique();
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => $colId,
            'name' => 'spatial-test-defaults',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $description = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $this->assertEquals(202, $description['headers']['status-code']);
        sleep(2);

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'description' => 'description'
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);

        // Test point with default value
        $point = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'loc',
            'required' => false,
            'default' => [0.0, 0.0]
        ]);

        $this->assertEquals(202, $point['headers']['status-code']);

        // Test line with default value
        $line = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => false,
            'default' => [[0.0, 0.0], [1.0, 1.0]]
        ]);

        $this->assertEquals(202, $line['headers']['status-code']);

        // Test polygon with default value
        $poly = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => false,
            'default' => [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]]
        ]);

        $this->assertEquals(202, $poly['headers']['status-code']);

        // Wait for attributes to be available
        sleep(2);

        // Create a new document without spatial data to test default values
        $newDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $colId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'description' => 'test default values'
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $newDocument['headers']['status-code']);

        $newDocumentId = $newDocument['body']['$id'];

        // Fetch the document to verify default values are applied
        $fetchedDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $colId . '/documents/' . $newDocumentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $fetchedDocument['headers']['status-code']);

        // Verify default values are applied
        $this->assertEquals([0.0, 0.0], $fetchedDocument['body']['loc']);
        $this->assertEquals([[0.0, 0.0], [1.0, 1.0]], $fetchedDocument['body']['route']);
        $this->assertEquals([[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]], $fetchedDocument['body']['area']);
    }
}
