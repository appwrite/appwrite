<?php

namespace Tests\E2E\Services\Databases\TablesDB;

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
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
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
        $this->assertEquals('tablesdb', $database['body']['type']);

        return ['databaseId' => $database['body']['$id']];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateTable(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
            'rowSecurity' => true,
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
     * @depends testCreateTable
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
            '/tablesdb/console/tables/' . $data['moviesId'] . '/rows',
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
            '/tablesdb/console/tables/' . $data['moviesId'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                // 'x-appwrite-project' => '', empty header
            ], $this->getHeaders())
        );
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('No Appwrite project was specified. Please specify your project ID when initializing your Appwrite SDK.', $response['body']['message']);
    }

    /**
     * @depends testCreateTable
     */
    public function testDisableTable(array $data): void
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Movies',
            'enabled' => false,
            'rowSecurity' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['enabled']);

        if ($this->getSide() === 'client') {
            $responseCreateRow = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'rowId' => ID::unique(),
                'data' => [
                    'title' => 'Captain America',
                ],
                'permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ],
            ]);

            $this->assertEquals(404, $responseCreateRow['headers']['status-code']);

            $responseListRow = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(404, $responseListRow['headers']['status-code']);

            $responseGetRow = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/someID', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(404, $responseGetRow['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Movies',
            'enabled' => true,
            'rowSecurity' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['enabled']);
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateColumns(array $data): array
    {
        $databaseId = $data['databaseId'];

        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $description = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $tagline = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'tagline',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $releaseYear = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYear',
            'required' => true,
            'min' => 1900,
            'max' => 2200,
        ]);

        $duration = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'duration',
            'required' => false,
            'min' => 60,
        ]);

        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $datetime = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'birthDay',
            'required' => false,
        ]);

        $relationship = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $data['actorsId'],
            'type' => 'oneToMany',
            'twoWay' => true,
            'key' => 'starringActors',
            'twoWayKey' => 'movie'
        ]);

        $integers = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/integer', array_merge([
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
        $this->assertEquals($relationship['body']['relatedTable'], $data['actorsId']);
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

        $movies = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertIsArray($movies['body']['columns']);
        $this->assertCount(9, $movies['body']['columns']);
        $this->assertEquals($movies['body']['columns'][0]['key'], $title['body']['key']);
        $this->assertEquals($movies['body']['columns'][1]['key'], $description['body']['key']);
        $this->assertEquals($movies['body']['columns'][2]['key'], $tagline['body']['key']);
        $this->assertEquals($movies['body']['columns'][3]['key'], $releaseYear['body']['key']);
        $this->assertEquals($movies['body']['columns'][4]['key'], $duration['body']['key']);
        $this->assertEquals($movies['body']['columns'][5]['key'], $actors['body']['key']);
        $this->assertEquals($movies['body']['columns'][6]['key'], $datetime['body']['key']);
        $this->assertEquals($movies['body']['columns'][7]['key'], $relationship['body']['key']);
        $this->assertEquals($movies['body']['columns'][8]['key'], $integers['body']['key']);

        return $data;
    }

    /**
     * @depends testCreateColumns
     */
    public function testListColumns(array $data): void
    {
        $databaseId = $data['databaseId'];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns', array_merge([
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
        $this->assertEquals(2, \count($response['body']['columns']));
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns', array_merge([
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
    public function testPatchColumn(array $data): void
    {
        $databaseId = $data['databaseId'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'patch',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals($table['body']['name'], 'patch');

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$table['body']['$id'].'/columns/string', array_merge([
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

        $index = $this->client->call(Client::METHOD_POST, '/tablesdb/'.$databaseId.'/tables/'.$table['body']['$id'].'/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'titleIndex',
            'type' => 'key',
            'columns' => ['title'],
        ]);
        $this->assertEquals(202, $index['headers']['status-code']);

        sleep(1);

        /**
         * Update column size to exceed Index maximum length
         */
        $attribute = $this->client->call(Client::METHOD_PATCH, '/tablesdb/'.$databaseId.'/tables/'.$table['body']['$id'].'/columns/string/'.$attribute['body']['key'], array_merge([
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

    public function testUpdateColumnEnum(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database 2'
        ]);

        $players = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $database['body']['$id'] . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Players',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create enum column
        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $database['body']['$id'] . '/tables/' . $players['body']['$id'] . '/columns/enum', array_merge([
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

        // Update enum column
        $attribute = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $database['body']['$id'] . '/tables/' . $players['body']['$id'] . '/columns/enum/' . $attribute['body']['key'], array_merge([
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
     * @depends testCreateColumns
     */
    public function testColumnResponseModels(array $data): array
    {
        $databaseId = $data['databaseId'];
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Response Models',
            // 'permissions' missing on purpose to make sure it's optional
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals($table['body']['name'], 'Response Models');

        $tableId = $table['body']['$id'];

        $columnsPath = "/tablesdb/" . $databaseId . "/tables/{$tableId}/columns";

        $string = $this->client->call(Client::METHOD_POST, $columnsPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 16,
            'required' => false,
            'default' => 'default',
        ]);

        $email = $this->client->call(Client::METHOD_POST, $columnsPath . '/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false,
            'default' => 'default@example.com',
        ]);

        $enum = $this->client->call(Client::METHOD_POST, $columnsPath . '/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', 'maybe'],
            'required' => false,
            'default' => 'maybe',
        ]);

        $ip = $this->client->call(Client::METHOD_POST, $columnsPath . '/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false,
            'default' => '192.0.2.0',
        ]);

        $url = $this->client->call(Client::METHOD_POST, $columnsPath . '/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'required' => false,
            'default' => 'http://example.com',
        ]);

        $integer = $this->client->call(Client::METHOD_POST, $columnsPath . '/integer', array_merge([
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

        $float = $this->client->call(Client::METHOD_POST, $columnsPath . '/float', array_merge([
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

        $boolean = $this->client->call(Client::METHOD_POST, $columnsPath . '/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boolean',
            'required' => false,
            'default' => true,
        ]);

        $datetime = $this->client->call(Client::METHOD_POST, $columnsPath . '/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'datetime',
            'required' => false,
            'default' => null,
        ]);

        $relationship = $this->client->call(Client::METHOD_POST, $columnsPath . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $data['actorsId'],
            'type' => 'oneToMany',
            'twoWay' => true,
            'key' => 'relationship',
            'twoWayKey' => 'twoWayKey'
        ]);

        $strings = $this->client->call(Client::METHOD_POST, $columnsPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'names',
            'size' => 512,
            'required' => false,
            'array' => true,
        ]);

        $integers = $this->client->call(Client::METHOD_POST, $columnsPath . '/integer', array_merge([
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
        $this->assertEquals($data['actorsId'], $relationship['body']['relatedTable']);
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

        $stringResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $string['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $emailResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $email['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $enumResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $enum['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $ipResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $ip['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $urlResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $url['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $integerResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $integer['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $floatResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $float['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $booleanResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $boolean['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $datetimeResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $datetime['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $relationshipResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $relationship['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $stringsResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $strings['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $integersResponse = $this->client->call(Client::METHOD_GET, $columnsPath . '/' . $integers['body']['key'], array_merge([
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
        $this->assertEquals($relationship['body']['relatedTable'], $relationshipResponse['body']['relatedTable']);
        $this->assertEquals($relationship['body']['relationType'], $relationshipResponse['body']['relationType']);
        $this->assertEquals($relationship['body']['twoWay'], $relationshipResponse['body']['twoWay']);
        $this->assertEquals($relationship['body']['twoWayKey'], $relationshipResponse['body']['twoWayKey']);

        $columns = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $columns['headers']['status-code']);
        $this->assertEquals(12, $columns['body']['total']);

        $columns = $columns['body']['columns'];
        $this->assertIsArray($columns);
        $this->assertCount(12, $columns);

        $this->assertEquals($stringResponse['body']['key'], $columns[0]['key']);
        $this->assertEquals($stringResponse['body']['type'], $columns[0]['type']);
        $this->assertEquals($stringResponse['body']['status'], $columns[0]['status']);
        $this->assertEquals($stringResponse['body']['required'], $columns[0]['required']);
        $this->assertEquals($stringResponse['body']['array'], $columns[0]['array']);
        $this->assertEquals($stringResponse['body']['size'], $columns[0]['size']);
        $this->assertEquals($stringResponse['body']['default'], $columns[0]['default']);

        $this->assertEquals($emailResponse['body']['key'], $columns[1]['key']);
        $this->assertEquals($emailResponse['body']['type'], $columns[1]['type']);
        $this->assertEquals($emailResponse['body']['status'], $columns[1]['status']);
        $this->assertEquals($emailResponse['body']['required'], $columns[1]['required']);
        $this->assertEquals($emailResponse['body']['array'], $columns[1]['array']);
        $this->assertEquals($emailResponse['body']['default'], $columns[1]['default']);
        $this->assertEquals($emailResponse['body']['format'], $columns[1]['format']);

        $this->assertEquals($enumResponse['body']['key'], $columns[2]['key']);
        $this->assertEquals($enumResponse['body']['type'], $columns[2]['type']);
        $this->assertEquals($enumResponse['body']['status'], $columns[2]['status']);
        $this->assertEquals($enumResponse['body']['required'], $columns[2]['required']);
        $this->assertEquals($enumResponse['body']['array'], $columns[2]['array']);
        $this->assertEquals($enumResponse['body']['default'], $columns[2]['default']);
        $this->assertEquals($enumResponse['body']['format'], $columns[2]['format']);
        $this->assertEquals($enumResponse['body']['elements'], $columns[2]['elements']);

        $this->assertEquals($ipResponse['body']['key'], $columns[3]['key']);
        $this->assertEquals($ipResponse['body']['type'], $columns[3]['type']);
        $this->assertEquals($ipResponse['body']['status'], $columns[3]['status']);
        $this->assertEquals($ipResponse['body']['required'], $columns[3]['required']);
        $this->assertEquals($ipResponse['body']['array'], $columns[3]['array']);
        $this->assertEquals($ipResponse['body']['default'], $columns[3]['default']);
        $this->assertEquals($ipResponse['body']['format'], $columns[3]['format']);

        $this->assertEquals($urlResponse['body']['key'], $columns[4]['key']);
        $this->assertEquals($urlResponse['body']['type'], $columns[4]['type']);
        $this->assertEquals($urlResponse['body']['status'], $columns[4]['status']);
        $this->assertEquals($urlResponse['body']['required'], $columns[4]['required']);
        $this->assertEquals($urlResponse['body']['array'], $columns[4]['array']);
        $this->assertEquals($urlResponse['body']['default'], $columns[4]['default']);
        $this->assertEquals($urlResponse['body']['format'], $columns[4]['format']);

        $this->assertEquals($integerResponse['body']['key'], $columns[5]['key']);
        $this->assertEquals($integerResponse['body']['type'], $columns[5]['type']);
        $this->assertEquals($integerResponse['body']['status'], $columns[5]['status']);
        $this->assertEquals($integerResponse['body']['required'], $columns[5]['required']);
        $this->assertEquals($integerResponse['body']['array'], $columns[5]['array']);
        $this->assertEquals($integerResponse['body']['default'], $columns[5]['default']);
        $this->assertEquals($integerResponse['body']['min'], $columns[5]['min']);
        $this->assertEquals($integerResponse['body']['max'], $columns[5]['max']);

        $this->assertEquals($floatResponse['body']['key'], $columns[6]['key']);
        $this->assertEquals($floatResponse['body']['type'], $columns[6]['type']);
        $this->assertEquals($floatResponse['body']['status'], $columns[6]['status']);
        $this->assertEquals($floatResponse['body']['required'], $columns[6]['required']);
        $this->assertEquals($floatResponse['body']['array'], $columns[6]['array']);
        $this->assertEquals($floatResponse['body']['default'], $columns[6]['default']);
        $this->assertEquals($floatResponse['body']['min'], $columns[6]['min']);
        $this->assertEquals($floatResponse['body']['max'], $columns[6]['max']);

        $this->assertEquals($booleanResponse['body']['key'], $columns[7]['key']);
        $this->assertEquals($booleanResponse['body']['type'], $columns[7]['type']);
        $this->assertEquals($booleanResponse['body']['status'], $columns[7]['status']);
        $this->assertEquals($booleanResponse['body']['required'], $columns[7]['required']);
        $this->assertEquals($booleanResponse['body']['array'], $columns[7]['array']);
        $this->assertEquals($booleanResponse['body']['default'], $columns[7]['default']);

        $this->assertEquals($datetimeResponse['body']['key'], $columns[8]['key']);
        $this->assertEquals($datetimeResponse['body']['type'], $columns[8]['type']);
        $this->assertEquals($datetimeResponse['body']['status'], $columns[8]['status']);
        $this->assertEquals($datetimeResponse['body']['required'], $columns[8]['required']);
        $this->assertEquals($datetimeResponse['body']['array'], $columns[8]['array']);
        $this->assertEquals($datetimeResponse['body']['default'], $columns[8]['default']);

        $this->assertEquals($relationshipResponse['body']['key'], $columns[9]['key']);
        $this->assertEquals($relationshipResponse['body']['type'], $columns[9]['type']);
        $this->assertEquals($relationshipResponse['body']['status'], $columns[9]['status']);
        $this->assertEquals($relationshipResponse['body']['required'], $columns[9]['required']);
        $this->assertEquals($relationshipResponse['body']['array'], $columns[9]['array']);
        $this->assertEquals($relationshipResponse['body']['relatedTable'], $columns[9]['relatedTable']);
        $this->assertEquals($relationshipResponse['body']['relationType'], $columns[9]['relationType']);
        $this->assertEquals($relationshipResponse['body']['twoWay'], $columns[9]['twoWay']);
        $this->assertEquals($relationshipResponse['body']['twoWayKey'], $columns[9]['twoWayKey']);

        $this->assertEquals($stringsResponse['body']['key'], $columns[10]['key']);
        $this->assertEquals($stringsResponse['body']['type'], $columns[10]['type']);
        $this->assertEquals($stringsResponse['body']['status'], $columns[10]['status']);
        $this->assertEquals($stringsResponse['body']['required'], $columns[10]['required']);
        $this->assertEquals($stringsResponse['body']['array'], $columns[10]['array']);
        $this->assertEquals($stringsResponse['body']['default'], $columns[10]['default']);

        $this->assertEquals($integersResponse['body']['key'], $columns[11]['key']);
        $this->assertEquals($integersResponse['body']['type'], $columns[11]['type']);
        $this->assertEquals($integersResponse['body']['status'], $columns[11]['status']);
        $this->assertEquals($integersResponse['body']['required'], $columns[11]['required']);
        $this->assertEquals($integersResponse['body']['array'], $columns[11]['array']);
        $this->assertEquals($integersResponse['body']['default'], $columns[11]['default']);
        $this->assertEquals($integersResponse['body']['min'], $columns[11]['min']);
        $this->assertEquals($integersResponse['body']['max'], $columns[11]['max']);

        $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $table['headers']['status-code']);

        $columns = $table['body']['columns'];

        $this->assertIsArray($columns);
        $this->assertCount(12, $columns);

        $this->assertEquals($stringResponse['body']['key'], $columns[0]['key']);
        $this->assertEquals($stringResponse['body']['type'], $columns[0]['type']);
        $this->assertEquals($stringResponse['body']['status'], $columns[0]['status']);
        $this->assertEquals($stringResponse['body']['required'], $columns[0]['required']);
        $this->assertEquals($stringResponse['body']['array'], $columns[0]['array']);
        $this->assertEquals($stringResponse['body']['size'], $columns[0]['size']);
        $this->assertEquals($stringResponse['body']['default'], $columns[0]['default']);

        $this->assertEquals($emailResponse['body']['key'], $columns[1]['key']);
        $this->assertEquals($emailResponse['body']['type'], $columns[1]['type']);
        $this->assertEquals($emailResponse['body']['status'], $columns[1]['status']);
        $this->assertEquals($emailResponse['body']['required'], $columns[1]['required']);
        $this->assertEquals($emailResponse['body']['array'], $columns[1]['array']);
        $this->assertEquals($emailResponse['body']['default'], $columns[1]['default']);
        $this->assertEquals($emailResponse['body']['format'], $columns[1]['format']);

        $this->assertEquals($enumResponse['body']['key'], $columns[2]['key']);
        $this->assertEquals($enumResponse['body']['type'], $columns[2]['type']);
        $this->assertEquals($enumResponse['body']['status'], $columns[2]['status']);
        $this->assertEquals($enumResponse['body']['required'], $columns[2]['required']);
        $this->assertEquals($enumResponse['body']['array'], $columns[2]['array']);
        $this->assertEquals($enumResponse['body']['default'], $columns[2]['default']);
        $this->assertEquals($enumResponse['body']['format'], $columns[2]['format']);
        $this->assertEquals($enumResponse['body']['elements'], $columns[2]['elements']);

        $this->assertEquals($ipResponse['body']['key'], $columns[3]['key']);
        $this->assertEquals($ipResponse['body']['type'], $columns[3]['type']);
        $this->assertEquals($ipResponse['body']['status'], $columns[3]['status']);
        $this->assertEquals($ipResponse['body']['required'], $columns[3]['required']);
        $this->assertEquals($ipResponse['body']['array'], $columns[3]['array']);
        $this->assertEquals($ipResponse['body']['default'], $columns[3]['default']);
        $this->assertEquals($ipResponse['body']['format'], $columns[3]['format']);

        $this->assertEquals($urlResponse['body']['key'], $columns[4]['key']);
        $this->assertEquals($urlResponse['body']['type'], $columns[4]['type']);
        $this->assertEquals($urlResponse['body']['status'], $columns[4]['status']);
        $this->assertEquals($urlResponse['body']['required'], $columns[4]['required']);
        $this->assertEquals($urlResponse['body']['array'], $columns[4]['array']);
        $this->assertEquals($urlResponse['body']['default'], $columns[4]['default']);
        $this->assertEquals($urlResponse['body']['format'], $columns[4]['format']);

        $this->assertEquals($integerResponse['body']['key'], $columns[5]['key']);
        $this->assertEquals($integerResponse['body']['type'], $columns[5]['type']);
        $this->assertEquals($integerResponse['body']['status'], $columns[5]['status']);
        $this->assertEquals($integerResponse['body']['required'], $columns[5]['required']);
        $this->assertEquals($integerResponse['body']['array'], $columns[5]['array']);
        $this->assertEquals($integerResponse['body']['default'], $columns[5]['default']);
        $this->assertEquals($integerResponse['body']['min'], $columns[5]['min']);
        $this->assertEquals($integerResponse['body']['max'], $columns[5]['max']);

        $this->assertEquals($floatResponse['body']['key'], $columns[6]['key']);
        $this->assertEquals($floatResponse['body']['type'], $columns[6]['type']);
        $this->assertEquals($floatResponse['body']['status'], $columns[6]['status']);
        $this->assertEquals($floatResponse['body']['required'], $columns[6]['required']);
        $this->assertEquals($floatResponse['body']['array'], $columns[6]['array']);
        $this->assertEquals($floatResponse['body']['default'], $columns[6]['default']);
        $this->assertEquals($floatResponse['body']['min'], $columns[6]['min']);
        $this->assertEquals($floatResponse['body']['max'], $columns[6]['max']);

        $this->assertEquals($booleanResponse['body']['key'], $columns[7]['key']);
        $this->assertEquals($booleanResponse['body']['type'], $columns[7]['type']);
        $this->assertEquals($booleanResponse['body']['status'], $columns[7]['status']);
        $this->assertEquals($booleanResponse['body']['required'], $columns[7]['required']);
        $this->assertEquals($booleanResponse['body']['array'], $columns[7]['array']);
        $this->assertEquals($booleanResponse['body']['default'], $columns[7]['default']);

        $this->assertEquals($datetimeResponse['body']['key'], $columns[8]['key']);
        $this->assertEquals($datetimeResponse['body']['type'], $columns[8]['type']);
        $this->assertEquals($datetimeResponse['body']['status'], $columns[8]['status']);
        $this->assertEquals($datetimeResponse['body']['required'], $columns[8]['required']);
        $this->assertEquals($datetimeResponse['body']['array'], $columns[8]['array']);
        $this->assertEquals($datetimeResponse['body']['default'], $columns[8]['default']);

        $this->assertEquals($relationshipResponse['body']['key'], $columns[9]['key']);
        $this->assertEquals($relationshipResponse['body']['type'], $columns[9]['type']);
        $this->assertEquals($relationshipResponse['body']['status'], $columns[9]['status']);
        $this->assertEquals($relationshipResponse['body']['required'], $columns[9]['required']);
        $this->assertEquals($relationshipResponse['body']['array'], $columns[9]['array']);
        $this->assertEquals($relationshipResponse['body']['relatedTable'], $columns[9]['relatedTable']);
        $this->assertEquals($relationshipResponse['body']['relationType'], $columns[9]['relationType']);
        $this->assertEquals($relationshipResponse['body']['twoWay'], $columns[9]['twoWay']);
        $this->assertEquals($relationshipResponse['body']['twoWayKey'], $columns[9]['twoWayKey']);

        $this->assertEquals($stringsResponse['body']['key'], $columns[10]['key']);
        $this->assertEquals($stringsResponse['body']['type'], $columns[10]['type']);
        $this->assertEquals($stringsResponse['body']['status'], $columns[10]['status']);
        $this->assertEquals($stringsResponse['body']['required'], $columns[10]['required']);
        $this->assertEquals($stringsResponse['body']['array'], $columns[10]['array']);
        $this->assertEquals($stringsResponse['body']['default'], $columns[10]['default']);

        $this->assertEquals($integersResponse['body']['key'], $columns[11]['key']);
        $this->assertEquals($integersResponse['body']['type'], $columns[11]['type']);
        $this->assertEquals($integersResponse['body']['status'], $columns[11]['status']);
        $this->assertEquals($integersResponse['body']['required'], $columns[11]['required']);
        $this->assertEquals($integersResponse['body']['array'], $columns[11]['array']);
        $this->assertEquals($integersResponse['body']['default'], $columns[11]['default']);
        $this->assertEquals($integersResponse['body']['min'], $columns[11]['min']);
        $this->assertEquals($integersResponse['body']['max'], $columns[11]['max']);

        /**
         * Test for FAILURE
         */
        $badEnum = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/enum', array_merge([
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
     * @depends testCreateColumns
     */
    public function testCreateIndexes(array $data): array
    {
        $databaseId = $data['databaseId'];

        $titleIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'titleIndex',
            'type' => 'fulltext',
            'columns' => ['title'],
        ]);

        $this->assertEquals(202, $titleIndex['headers']['status-code']);
        $this->assertEquals('titleIndex', $titleIndex['body']['key']);
        $this->assertEquals('fulltext', $titleIndex['body']['type']);
        $this->assertCount(1, $titleIndex['body']['columns']);
        $this->assertEquals('title', $titleIndex['body']['columns'][0]);

        $releaseYearIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYear',
            'type' => 'key',
            'columns' => ['releaseYear'],
        ]);

        $this->assertEquals(202, $releaseYearIndex['headers']['status-code']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['key']);
        $this->assertEquals('key', $releaseYearIndex['body']['type']);
        $this->assertCount(1, $releaseYearIndex['body']['columns']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['columns'][0]);

        $releaseWithDate1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYearDated',
            'type' => 'key',
            'columns' => ['releaseYear', '$createdAt', '$updatedAt'],
        ]);

        $this->assertEquals(202, $releaseWithDate1['headers']['status-code']);
        $this->assertEquals('releaseYearDated', $releaseWithDate1['body']['key']);
        $this->assertEquals('key', $releaseWithDate1['body']['type']);
        $this->assertCount(3, $releaseWithDate1['body']['columns']);
        $this->assertEquals('releaseYear', $releaseWithDate1['body']['columns'][0]);
        $this->assertEquals('$createdAt', $releaseWithDate1['body']['columns'][1]);
        $this->assertEquals('$updatedAt', $releaseWithDate1['body']['columns'][2]);

        $releaseWithDate2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'birthDay',
            'type' => 'key',
            'columns' => ['birthDay'],
        ]);

        $this->assertEquals(202, $releaseWithDate2['headers']['status-code']);
        $this->assertEquals('birthDay', $releaseWithDate2['body']['key']);
        $this->assertEquals('key', $releaseWithDate2['body']['type']);
        $this->assertCount(1, $releaseWithDate2['body']['columns']);
        $this->assertEquals('birthDay', $releaseWithDate2['body']['columns'][0]);

        // Test for failure
        $fulltextReleaseYear = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYearDated',
            'type' => 'fulltext',
            'columns' => ['releaseYear'],
        ]);

        $this->assertEquals(400, $fulltextReleaseYear['headers']['status-code']);
        $this->assertEquals($fulltextReleaseYear['body']['message'], 'Attribute "releaseYear" cannot be part of a fulltext index, must be of type string');

        $noAttributes = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'none',
            'type' => 'key',
            'columns' => [],
        ]);

        $this->assertEquals(400, $noAttributes['headers']['status-code']);
        $this->assertEquals($noAttributes['body']['message'], 'No attributes provided for index');

        $duplicates = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'duplicate',
            'type' => 'fulltext',
            'columns' => ['releaseYear', 'releaseYear'],
        ]);

        $this->assertEquals(400, $duplicates['headers']['status-code']);
        $this->assertEquals($duplicates['body']['message'], 'Duplicate attributes provided');

        $tooLong = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'tooLong',
            'type' => 'key',
            'columns' => ['description', 'tagline'],
        ]);

        $this->assertEquals(400, $tooLong['headers']['status-code']);
        $this->assertStringContainsString('Index length is longer than the maximum', $tooLong['body']['message']);

        $fulltextArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'ft',
            'type' => 'fulltext',
            'columns' => ['actors'],
        ]);

        $this->assertEquals(400, $fulltextArray['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $fulltextArray['body']['message']);

        $actorsArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-actors',
            'type' => 'key',
            'columns' => ['actors'],
        ]);

        $this->assertEquals(400, $actorsArray['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $actorsArray['body']['message']);

        $twoLevelsArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-ip-actors',
            'type' => 'key',
            'columns' => ['releaseYear', 'actors'], // 2 levels
            'orders' => ['DESC', 'DESC'],
        ]);

        $this->assertEquals(400, $twoLevelsArray['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $twoLevelsArray['body']['message']);

        $unknown = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-unknown',
            'type' => 'key',
            'columns' => ['Unknown'],
        ]);

        $this->assertEquals(400, $unknown['headers']['status-code']);
        $this->assertEquals('The column \'Unknown\' required for the index could not be found. Please confirm all your columns are in the available state.', $unknown['body']['message']);

        $index1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'integers-order',
            'type' => 'key',
            'columns' => ['integers'], // array column
            'orders' => ['DESC'], // Check order is removed in API
        ]);

        $this->assertEquals(400, $index1['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $index1['body']['message']);

        $index2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'integers-size',
            'type' => 'key',
            'columns' => ['integers'], // array column
        ]);

        $this->assertEquals(400, $index2['headers']['status-code']);
        $this->assertEquals('Creating indexes on array attributes is not currently supported.', $index2['body']['message']);

        /**
         * Create Indexes by worker
         */
        sleep(2);

        $movies = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'], array_merge([
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
        foreach ($movies['body']['indexes'] as $index) {
            $this->assertEquals('available', $index['status']);
        }

        return $data;
    }

    /**
     * @depends testCreateColumns
     */
    public function testGetIndexByKeyWithLengths(array $data): void
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['moviesId'];

        // Test case for valid lengths
        $create = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthTestIndex',
            'type' => 'key',
            'columns' => ['title','description'],
            'lengths' => [128,200]
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        // Fetch index and check correct lengths
        $index = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/indexes/lengthTestIndex", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $index['headers']['status-code']);
        $this->assertEquals('lengthTestIndex', $index['body']['key']);
        $this->assertEquals([128, 200], $index['body']['lengths']);

        // Test case for count of lengths greater than attributes (should throw 400)
        $create = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthCountExceededIndex',
            'type' => 'key',
            'columns' => ['title'],
            'lengths' => [128, 128]
        ]);
        $this->assertEquals(400, $create['headers']['status-code']);

        // Test case for lengths exceeding total of 768
        $create = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthTooLargeIndex',
            'type' => 'key',
            'columns' => ['title','description','tagline','actors'],
            'lengths' => [256,256,256,20]
        ]);

        $this->assertEquals(400, $create['headers']['status-code']);

        // Test case for negative length values
        $create = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'negativeLengthIndex',
            'type' => 'key',
            'columns' => ['title'],
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
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
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
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
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
    public function testCreateRow(array $data): array
    {
        $databaseId = $data['databaseId'];
        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $row4 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'releaseYear' => 2020, // Missing title, expect an 400 error
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row1['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $row1['body']['$tableId']);
        $this->assertArrayNotHasKey('$table', $row1['body']);
        $this->assertEquals($databaseId, $row1['body']['$databaseId']);
        $this->assertEquals($row1['body']['title'], 'Captain America');
        $this->assertEquals($row1['body']['releaseYear'], 1944);
        $this->assertIsArray($row1['body']['$permissions']);
        $this->assertCount(3, $row1['body']['$permissions']);
        $this->assertCount(2, $row1['body']['actors']);
        $this->assertEquals($row1['body']['actors'][0], 'Chris Evans');
        $this->assertEquals($row1['body']['actors'][1], 'Samuel Jackson');
        $this->assertEquals($row1['body']['birthDay'], '1975-06-12T12:12:55.000+00:00');
        $this->assertTrue(array_key_exists('$sequence', $row1['body']));
        $this->assertIsInt($row1['body']['$sequence']);

        $this->assertEquals(201, $row2['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $row2['body']['$tableId']);
        $this->assertArrayNotHasKey('$table', $row2['body']);
        $this->assertEquals($databaseId, $row2['body']['$databaseId']);
        $this->assertEquals($row2['body']['title'], 'Spider-Man: Far From Home');
        $this->assertEquals($row2['body']['releaseYear'], 2019);
        $this->assertEquals($row2['body']['duration'], null);
        $this->assertIsArray($row2['body']['$permissions']);
        $this->assertCount(3, $row2['body']['$permissions']);
        $this->assertCount(3, $row2['body']['actors']);
        $this->assertEquals($row2['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($row2['body']['actors'][1], 'Zendaya Maree Stoermer');
        $this->assertEquals($row2['body']['actors'][2], 'Samuel Jackson');
        $this->assertEquals($row2['body']['birthDay'], null);
        $this->assertEquals($row2['body']['integers'][0], 50);
        $this->assertEquals($row2['body']['integers'][1], 60);

        $this->assertEquals(201, $row3['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $row3['body']['$tableId']);
        $this->assertArrayNotHasKey('$table', $row3['body']);
        $this->assertEquals($databaseId, $row3['body']['$databaseId']);
        $this->assertEquals($row3['body']['title'], 'Spider-Man: Homecoming');
        $this->assertEquals($row3['body']['releaseYear'], 2017);
        $this->assertEquals($row3['body']['duration'], 65);
        $this->assertIsArray($row3['body']['$permissions']);
        $this->assertCount(3, $row3['body']['$permissions']);
        $this->assertCount(2, $row3['body']['actors']);
        $this->assertEquals($row3['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($row3['body']['actors'][1], 'Zendaya Maree Stoermer');
        $this->assertEquals($row3['body']['birthDay'], '1975-06-12T18:12:55.000+00:00'); // UTC for NY

        $this->assertEquals(400, $row4['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateIndexes
     */
    public function testUpsertRow(array $data): void
    {
        $databaseId = $data['databaseId'];
        $rowId = ID::unique();
        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertCount(3, $row['body']['$permissions']);
        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Thor: Ragnarok', $row['body']['title']);

        /**
         * Resubmit same document, nothing to update
         */
        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $row['body']['title']);
        $this->assertCount(3, $row['body']['$permissions']);

        /**
         * Do not allow array list
         */
        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                [
                    'title' => 'Thor: Ragnarok 1',
                ],
                [
                    'title' => 'Thor: Ragnarok 2',
                ]
            ],
            'permissions' => [
                Permission::read(Role::users()),
            ],
        ]);
        $this->assertEquals(400, $row['headers']['status-code']);

        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals('Thor: Love and Thunder', $row['body']['title']);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Thor: Love and Thunder', $row['body']['title']);

        // removing permission to read and delete
        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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
        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        switch ($this->getSide()) {
            case 'client':
                $this->assertEquals(404, $row['headers']['status-code']);
                break;
            case 'server':
                $this->assertEquals(200, $row['headers']['status-code']);
                break;
        }
        // shouldn't be able to delete as no delete permission
        $row = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        // simulating for the client
        // the row should not be allowed to be deleted as needed downward
        if ($this->getSide() === 'client') {
            $this->assertEquals(401, $row['headers']['status-code']);
        }
        // giving the delete permission
        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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

        $row = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $row['headers']['status-code']);

        // relationship behaviour
        $person = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'person-upsert',
            'name' => 'person',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
                Permission::create(Role::users()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $person['headers']['status-code']);

        $library = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'library-upsert',
            'name' => 'library',
            'permissions' => [
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::create(Role::users()),
                Permission::delete(Role::users()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $library['headers']['status-code']);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fullName',
            'size' => 255,
            'required' => false,
        ]);

        sleep(1); // Wait for worker

        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => 'library-upsert',
            'type' => Database::RELATION_ONE_TO_ONE,
            'key' => 'library',
            'twoWay' => true,
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);

        sleep(1); // Wait for worker

        $libraryName = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $library['body']['$id'] . '/columns/string', array_merge([
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
        $rowId = ID::unique();
        $person1 = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows/'.$rowId, array_merge([
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
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString(),
                Query::equal('library', ['library1'])->toString(),
            ],
        ]);

        $this->assertEquals(1, $rows['body']['total']);
        $this->assertEquals('Library 1', $rows['body']['rows'][0]['library']['libraryName']);


        $person1 = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows/'.$rowId, array_merge([
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
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString(),
                Query::equal('library', ['library1'])->toString(),
            ],
        ]);

        $this->assertEquals(1, $rows['body']['total']);
        $this->assertEquals('Library 2', $rows['body']['rows'][0]['library']['libraryName']);

        // data should get added
        $person1 = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows/'.ID::unique(), array_merge([
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

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString()
            ],
        ]);

        $this->assertEquals(2, $rows['body']['total']);

        // test without passing permissions
        $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2000
            ]
        ]);

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals('Thor: Ragnarok', $row['body']['title']);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $row['headers']['status-code']);

        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleteResponse['headers']['status-code']);

        if ($this->getSide() === 'client') {
            // Skipped on server side: Creating a row with no permissions results in an empty permissions array, whereas on client side it assigns permissions to the current user

            // test without passing permissions
            $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                    'releaseYear' => 2000
                ]
            ]);

            $this->assertEquals(200, $row['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $row['body']['title']);
            $this->assertCount(3, $row['body']['$permissions']);
            $permissionsCreated = $row['body']['$permissions'];
            // checking the default created permission
            $defaultPermission = [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id']))
            ];
            // ignoring the order of the permission and checking the permissions
            $this->assertEqualsCanonicalizing($defaultPermission, $permissionsCreated);

            $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(200, $row['headers']['status-code']);

            // updating the created doc
            $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'data' => [
                    'title' => 'Thor: Ragnarok',
                    'releaseYear' => 2002
                ]
            ]);
            $this->assertEquals(200, $row['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $row['body']['title']);
            $this->assertEquals(2002, $row['body']['releaseYear']);
            $this->assertCount(3, $row['body']['$permissions']);
            $this->assertEquals($permissionsCreated, $row['body']['$permissions']);

            // removing the delete permission
            $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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
            $this->assertEquals(200, $row['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $row['body']['title']);
            $this->assertEquals(2002, $row['body']['releaseYear']);
            $this->assertCount(1, $row['body']['$permissions']);

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(401, $deleteResponse['headers']['status-code']);

            // giving the delete permission
            $row = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
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
            $this->assertEquals(200, $row['headers']['status-code']);
            $this->assertEquals('Thor: Ragnarok', $row['body']['title']);
            $this->assertEquals(2002, $row['body']['releaseYear']);
            $this->assertCount(2, $row['body']['$permissions']);

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(204, $deleteResponse['headers']['status-code']);

            // upsert for the related row without passing permissions
            // data should get added
            $newPersonId = ID::unique();
            $personNoPerm = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows/' . $newPersonId, array_merge([
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
            $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'queries' => [
                    Query::select(['fullName', 'library.*'])->toString()
                ],
            ]);
            $this->assertGreaterThanOrEqual(1, $rows['body']['total']);
            $rowsDetails = $rows['body']['rows'];
            foreach ($rowsDetails as $doc) {
                $this->assertCount(3, $doc['$permissions']);
            }
            $found = false;
            foreach ($rows['body']['rows'] as $doc) {
                if (isset($doc['library']['libraryName']) && $doc['library']['libraryName'] === 'Library 3') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Library 3 should be present in the upserted rows.');

            // Fetch the related library and assert on its permissions (should be default/inherited)
            $library3 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $library['body']['$id'] . '/rows/library3', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $library3['headers']['status-code']);
            $this->assertEquals('Library 3', $library3['body']['libraryName']);
            $this->assertArrayHasKey('$permissions', $library3['body']);
            $this->assertCount(3, $library3['body']['$permissions']);
            $this->assertNotEmpty($library3['body']['$permissions']);
        }
    }

    /**
     * @depends testCreateRow
     */
    public function testListRows(array $data): array
    {
        $databaseId = $data['databaseId'];
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1944, $rows['body']['rows'][0]['releaseYear']);
        $this->assertEquals(2017, $rows['body']['rows'][1]['releaseYear']);
        $this->assertEquals(2019, $rows['body']['rows'][2]['releaseYear']);
        $this->assertFalse(array_key_exists('$internalId', $rows['body']['rows'][0]));
        $this->assertFalse(array_key_exists('$internalId', $rows['body']['rows'][1]));
        $this->assertFalse(array_key_exists('$internalId', $rows['body']['rows'][2]));
        $this->assertCount(3, $rows['body']['rows']);

        foreach ($rows['body']['rows'] as $row) {
            $this->assertArrayNotHasKey('$table', $row);
            $this->assertEquals($databaseId, $row['$databaseId']);
            $this->assertEquals($data['moviesId'], $row['$tableId']);
        }

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1944, $rows['body']['rows'][2]['releaseYear']);
        $this->assertEquals(2017, $rows['body']['rows'][1]['releaseYear']);
        $this->assertEquals(2019, $rows['body']['rows'][0]['releaseYear']);
        $this->assertCount(3, $rows['body']['rows']);

        // changing description column to be null by default instead of empty string
        $patchNull = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/columns/string/description', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => null,
            'required' => false,
        ]);

        // creating a dummy doc with null description
        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Dummy',
                'releaseYear' => 1944,
                'birthDay' => '1975-06-12 14:12:55+02:00',
                'actors' => [
                    'Dummy',
                ],
            ]
        ]);

        $this->assertEquals(201, $row1['headers']['status-code']);
        // fetching docs with cursor after the dummy doc with order attr description which is null
        $rowsPaginated = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('dummy')->toString(),
                Query::cursorAfter(new Document(['$id' => $row1['body']['$id']]))->toString()
            ],
        ]);
        // should throw 400 as the order attr description of the selected doc is null
        $this->assertEquals(400, $rowsPaginated['headers']['status-code']);

        // deleting the dummy doc created
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $row1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        return ['rows' => $rows['body']['rows'], 'databaseId' => $databaseId];
    }

    /**
     * @depends testListRows
     */
    public function testGetRow(array $data): void
    {
        $databaseId = $data['databaseId'];
        foreach ($data['rows'] as $row) {
            $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $row['$tableId'] . '/rows/' . $row['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($response['body']['$id'], $row['$id']);
            $this->assertEquals($row['$tableId'], $response['body']['$tableId']);
            $this->assertArrayNotHasKey('$table', $response['body']);
            $this->assertEquals($row['$databaseId'], $response['body']['$databaseId']);
            $this->assertEquals($response['body']['title'], $row['title']);
            $this->assertEquals($response['body']['releaseYear'], $row['releaseYear']);
            $this->assertEquals($response['body']['$permissions'], $row['$permissions']);
            $this->assertEquals($response['body']['birthDay'], $row['birthDay']);
            $this->assertFalse(array_key_exists('$internalId', $response['body']));
            $this->assertFalse(array_key_exists('$tenant', $response['body']));
        }
    }

    /**
     * @depends testListRows
     */
    public function testGetRowWithQueries(array $data): void
    {
        $databaseId = $data['databaseId'];
        $row = $data['rows'][0];

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $row['$tableId'] . '/rows/' . $row['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['title', 'releaseYear', '$id'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($row['title'], $response['body']['title']);
        $this->assertEquals($row['releaseYear'], $response['body']['releaseYear']);
        $this->assertArrayNotHasKey('birthDay', $response['body']);
    }

    /**
     * @depends testCreateRow
     */
    public function testListRowsAfterPagination(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test after without order.
         */
        $base = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals('Captain America', $base['body']['rows'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['rows'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['rows'][2]['title']);
        $this->assertCount(3, $base['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['rows'][0]['$id']]))->toString()
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($base['body']['rows'][1]['$id'], $rows['body']['rows'][0]['$id']);
        $this->assertEquals($base['body']['rows'][2]['$id'], $rows['body']['rows'][1]['$id']);
        $this->assertCount(2, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['rows'][2]['$id']]))->toString()
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEmpty($rows['body']['rows']);

        /**
         * Test with ASC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['rows'][0]['releaseYear']);
        $this->assertEquals(2017, $base['body']['rows'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['rows'][2]['releaseYear']);
        $this->assertCount(3, $base['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['rows'][1]['$id']]))->toString(),
                Query::orderAsc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($base['body']['rows'][2]['$id'], $rows['body']['rows'][0]['$id']);
        $this->assertCount(1, $rows['body']['rows']);

        /**
         * Test with DESC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['rows'][2]['releaseYear']);
        $this->assertEquals(2017, $base['body']['rows'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['rows'][0]['releaseYear']);
        $this->assertCount(3, $base['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['rows'][1]['$id']]))->toString(),
                Query::orderDesc('releaseYear')->toString()
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($base['body']['rows'][2]['$id'], $rows['body']['rows'][0]['$id']);
        $this->assertCount(1, $rows['body']['rows']);

        /**
         * Test after with unknown row.
         */
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $rows['headers']['status-code']);

        /**
         * Test null value for cursor
         */

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"cursorAfter","values":[null]}',
            ],
        ]);

        $this->assertEquals(400, $rows['headers']['status-code']);

        return [];
    }

    /**
     * @depends testCreateRow
     */
    public function testListRowsBeforePagination(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test before without order.
         */
        $base = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals('Captain America', $base['body']['rows'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['rows'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['rows'][2]['title']);
        $this->assertCount(3, $base['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['rows'][2]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($base['body']['rows'][0]['$id'], $rows['body']['rows'][0]['$id']);
        $this->assertEquals($base['body']['rows'][1]['$id'], $rows['body']['rows'][1]['$id']);
        $this->assertCount(2, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['rows'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEmpty($rows['body']['rows']);

        /**
         * Test with ASC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['rows'][0]['releaseYear']);
        $this->assertEquals(2017, $base['body']['rows'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['rows'][2]['releaseYear']);
        $this->assertCount(3, $base['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['rows'][1]['$id']]))->toString(),
                Query::orderAsc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($base['body']['rows'][0]['$id'], $rows['body']['rows'][0]['$id']);
        $this->assertCount(1, $rows['body']['rows']);

        /**
         * Test with DESC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals(1944, $base['body']['rows'][2]['releaseYear']);
        $this->assertEquals(2017, $base['body']['rows'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['rows'][0]['releaseYear']);
        $this->assertCount(3, $base['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['rows'][1]['$id']]))->toString(),
                Query::orderDesc('releaseYear')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals($base['body']['rows'][0]['$id'], $rows['body']['rows'][0]['$id']);
        $this->assertCount(1, $rows['body']['rows']);

        return [];
    }

    /**
     * @depends testCreateRow
     */
    public function testListRowsLimitAndOffset(array $data): array
    {
        $databaseId = $data['databaseId'];
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1944, $rows['body']['rows'][0]['releaseYear']);
        $this->assertCount(1, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderAsc('releaseYear')->toString(),
                Query::limit(2)->toString(),
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(2017, $rows['body']['rows'][0]['releaseYear']);
        $this->assertEquals(2019, $rows['body']['rows'][1]['releaseYear']);
        $this->assertCount(2, $rows['body']['rows']);

        return [];
    }

    /**
     * @depends testCreateRow
     */
    public function testRowsListQueries(array $data): array
    {
        $databaseId = $data['databaseId'];
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', 'Captain America')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1944, $rows['body']['rows'][0]['releaseYear']);
        $this->assertCount(1, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', [$rows['body']['rows'][0]['$id']])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1944, $rows['body']['rows'][0]['releaseYear']);
        $this->assertCount(1, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', 'Homecoming')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(2017, $rows['body']['rows'][0]['releaseYear']);
        $this->assertCount(1, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', 'spider')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(2019, $rows['body']['rows'][0]['releaseYear']);
        $this->assertEquals(2017, $rows['body']['rows'][1]['releaseYear']);
        $this->assertCount(2, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"contains","attribute":"title","values":[bad]}'
            ],
        ]);

        $this->assertEquals(400, $rows['headers']['status-code']);
        $this->assertEquals('Invalid query: Syntax error', $rows['body']['message']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('title', ['spi'])->toString(), // like query
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(2, $rows['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('releaseYear', [1944])->toString(),
            ],
        ]);

        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Captain America', $rows['body']['rows'][0]['title']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::notEqual('releaseYear', 1944)->toString(),
            ],
        ]);

        $this->assertCount(2, $rows['body']['rows']);
        $this->assertEquals('Spider-Man: Far From Home', $rows['body']['rows'][0]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $rows['body']['rows'][1]['title']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('$createdAt', '1976-06-12')->toString(),
            ],
        ]);

        $this->assertCount(3, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('$createdAt', '1976-06-12')->toString(),
            ],
        ]);

        $this->assertCount(0, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('actors', ['Tom Holland', 'Samuel Jackson'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(3, $rows['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('actors', ['Tom'])->toString(), // Full-match not like
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(0, $rows['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('birthDay', '16/01/2024 12:00:00AM')->toString(),
            ],
        ]);

        $this->assertEquals(400, $rows['headers']['status-code']);
        $this->assertEquals('Invalid query: Query value is invalid for attribute "birthDay"', $rows['body']['message']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('birthDay', '1960-01-01 10:10:10+02:30')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals('1975-06-12T12:12:55.000+00:00', $rows['body']['rows'][0]['birthDay']);
        $this->assertEquals('1975-06-12T18:12:55.000+00:00', $rows['body']['rows'][1]['birthDay']);
        $this->assertCount(2, $rows['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::isNull('integers')->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1, $rows['body']['total']);

        /**
         * Test for Failure
         */
        $conditions = [];

        for ($i = 0; $i < APP_DATABASE_QUERY_MAX_VALUES + 1; $i++) {
            $conditions[] = $i;
        }

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('releaseYear', $conditions)->toString(),
            ],
        ]);
        $this->assertEquals(400, $rows['headers']['status-code']);
        $this->assertEquals('Invalid query: Query on attribute has greater than '.APP_DATABASE_QUERY_MAX_VALUES.' values: releaseYear', $rows['body']['message']);

        $value = '';

        for ($i = 0; $i < 101; $i++) {
            $value .= "[" . $i . "] Too long title to cross 2k chars query limit ";
        }

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('title', $value)->toString(),
            ],
        ]);

        // Todo: Not sure what to do we with Query length Test VS old? JSON validator will fails if query string will be truncated?
        //$this->assertEquals(400, $rows['headers']['status-code']);

        // Todo: Disabled for CL - Uncomment after ProxyDatabase cleanup for find method
        // $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), [
        //     'queries' => [
        //         Query::search('actors', 'Tom')->toString(),
        //     ],
        // ]);
        // $this->assertEquals(400, $rows['headers']['status-code']);
        // $this->assertEquals('Invalid query: Cannot query search on attribute "actors" because it is an array.', $rows['body']['message']);

        return [];
    }

    /**
     * @depends testCreateRow
     */
    public function testUpdateRow(array $data): array
    {
        $databaseId = $data['databaseId'];
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $id = $row['body']['$id'];

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $row['body']['$tableId']);
        $this->assertArrayNotHasKey('$table', $row['body']);
        $this->assertEquals($databaseId, $row['body']['$databaseId']);
        $this->assertEquals($row['body']['title'], 'Thor: Ragnaroc');
        $this->assertEquals($row['body']['releaseYear'], 2017);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($row['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($row['body']['birthDay']));
        $this->assertContains(Permission::read(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
        $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);

        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
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

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($row['body']['$id'], $id);
        $this->assertEquals($data['moviesId'], $row['body']['$tableId']);
        $this->assertArrayNotHasKey('$table', $row['body']);
        $this->assertEquals($databaseId, $row['body']['$databaseId']);
        $this->assertEquals($row['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($row['body']['releaseYear'], 2017);
        $this->assertContains(Permission::read(Role::users()), $row['body']['$permissions']);
        $this->assertContains(Permission::update(Role::users()), $row['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::users()), $row['body']['$permissions']);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $id = $row['body']['$id'];

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($data['moviesId'], $row['body']['$tableId']);
        $this->assertArrayNotHasKey('$table', $row['body']);
        $this->assertEquals($databaseId, $row['body']['$databaseId']);
        $this->assertEquals($row['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($row['body']['releaseYear'], 2017);

        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timestamp' => DateTime::formatTz(DateTime::now()),
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for failure
         */

        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timestamp' => 'invalid',
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid X-Appwrite-Timestamp header value', $response['body']['message']);
        $this->assertEquals(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);

        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timestamp' => DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -1000)),
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);
        $this->assertEquals('Remote row is newer than local.', $response['body']['message']);
        $this->assertEquals(Exception::ROW_UPDATE_CONFLICT, $response['body']['type']);

        return [];
    }

    public function testOperators(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database for Operators'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'tableId' => ID::unique(),
            'name' => 'Operator Tests',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'releaseYear',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'duration',
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'integers',
            'required' => false,
            'array' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'tagline',
            'size' => 512,
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/datetime', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'birthDay',
            'required' => false,
        ]);

        // Wait for columns to be created
        sleep(2);

        // Create a row to test operators
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $this->assertEquals(201, $row['headers']['status-code']);
        $rowId = $row['body']['$id'];

        // Test increment operator on integer
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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
        $upserted = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $upsertId, array_merge([
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

        $upserted = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $upsertId, array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database for Bulk Operators'
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create table
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'tableId' => ID::unique(),
            'name' => 'Bulk Operator Tests',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::users()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'releaseYear',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/datetime', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'birthDay',
            'required' => false,
        ]);

        // Wait for columns to be created
        sleep(2);

        // Create multiple rows
        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $this->assertEquals(201, $row1['headers']['status-code']);
        $this->assertEquals(201, $row2['headers']['status-code']);

        // Test bulk update with operators
        $bulkUpdate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
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
        $verify1 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $verify1['headers']['status-code']);
        $this->assertEquals(2030, $verify1['body']['releaseYear']);

        $verify2 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $verify2['headers']['status-code']);
        $this->assertEquals(2031, $verify2['body']['releaseYear']);
    }

    /**
     * @depends testCreateRow
     */
    public function testDeleteRow(array $data): array
    {
        $databaseId = $data['databaseId'];
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $id = $row['body']['$id'];

        $this->assertEquals(201, $row['headers']['status-code']);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $row['headers']['status-code']);

        $row = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $row['headers']['status-code']);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $row['headers']['status-code']);

        return $data;
    }

    public function testInvalidRowStructure(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'InvalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('InvalidRowDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'invalidRowStructure',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals('invalidRowStructure', $table['body']['name']);

        $tableId = $table['body']['$id'];

        $email = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false,
        ]);

        $enum = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', 'maybe'],
            'required' => false,
        ]);

        $ip = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false,
        ]);

        $url = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'size' => 256,
            'required' => false,
        ]);

        $range = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
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
        $floatRange = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'floatRange',
            'required' => false,
            'min' => 1.1,
            'max' => 1.4,
        ]);

        $probability = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/float', array_merge([
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

        $upperBound = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'upperBound',
            'required' => false,
            'max' => 10,
        ]);

        $lowerBound = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
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

        $invalidRange = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'invalidRange',
            'required' => false,
            'min' => 4,
            'max' => 3,
        ]);

        $defaultArray = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'defaultArray',
            'required' => false,
            'default' => 42,
            'array' => true,
        ]);

        $defaultRequired = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => ID::custom('defaultRequired'),
            'required' => true,
            'default' => 12
        ]);

        $enumDefault = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => ID::custom('enumDefault'),
            'elements' => ['north', 'west'],
            'default' => 'south'
        ]);

        $enumDefaultStrict = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => ID::custom('enumDefault'),
            'elements' => ['north', 'west'],
            'default' => 'NORTH'
        ]);

        $goodDatetime = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'birthDay',
            'required' => false,
            'default' => null
        ]);

        $datetimeDefault = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/datetime', array_merge([
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
        $this->assertEquals('Cannot set default value for array columns', $defaultArray['body']['message']);
        $this->assertEquals(400, $datetimeDefault['headers']['status-code']);

        // wait for worker to add attributes
        sleep(3);

        $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertCount(10, $table['body']['columns']);

        /**
         * Test for successful validation
         */

        $goodEmail = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'email' => 'user@example.com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodEnum = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'enum' => 'yes',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodIp = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'ip' => '1.1.1.1',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodUrl = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'url' => 'http://www.example.com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodRange = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'range' => 3,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodFloatRange = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'floatRange' => 1.4,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $goodProbability = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'probability' => 0.99999,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $notTooHigh = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'upperBound' => 8,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $notTooLow = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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
         * Test that custom validators reject rows
         */

        $badEmail = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'email' => 'user@@example.com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badEnum = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'enum' => 'badEnum',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badIp = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'ip' => '1.1.1.1.1',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badUrl = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'url' => 'example...com',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badRange = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'range' => 11,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badFloatRange = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'floatRange' => 2.5,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badProbability = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'probability' => 1.1,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $tooHigh = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'upperBound' => 11,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $tooLow = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'lowerBound' => 3,
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $badTime = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => 'unique()',
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

        // TODO: @itznotabug - database library needs to throw error based on context!
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
     * @depends testDeleteRow
     */
    public function testDefaultPermissions(array $data): array
    {
        $databaseId = $data['databaseId'];
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [],
            ],
        ]);

        $id = $row['body']['$id'];

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertEquals($row['body']['title'], 'Captain America');
        $this->assertEquals($row['body']['releaseYear'], 1944);
        $this->assertIsArray($row['body']['$permissions']);

        if ($this->getSide() == 'client') {
            $this->assertCount(3, $row['body']['$permissions']);
            $this->assertContains(Permission::read(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
            $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
            $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
        }

        if ($this->getSide() == 'server') {
            $this->assertCount(0, $row['body']['$permissions']);
            $this->assertEquals([], $row['body']['$permissions']);
        }

        // Updated Permissions

        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
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

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($row['body']['title'], 'Captain America 2');
        $this->assertEquals($row['body']['releaseYear'], 1945);

        // This differs from the old permissions model because we don't inherit
        // existing row permissions on update, unless none were supplied,
        // so that specific types can be removed if wanted.
        $this->assertCount(2, $row['body']['$permissions']);
        $this->assertEquals([
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
        ], $row['body']['$permissions']);

        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($row['body']['title'], 'Captain America 2');
        $this->assertEquals($row['body']['releaseYear'], 1945);

        $this->assertCount(2, $row['body']['$permissions']);
        $this->assertEquals([
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
        ], $row['body']['$permissions']);

        // Reset Permissions

        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
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

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals($row['body']['title'], 'Captain America 3');
        $this->assertEquals($row['body']['releaseYear'], 1946);
        $this->assertCount(0, $row['body']['$permissions']);
        $this->assertEquals([], $row['body']['$permissions']);

        // Check client side can no longer read the row.
        $row = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        switch ($this->getSide()) {
            case 'client':
                $this->assertEquals(404, $row['headers']['status-code']);
                break;
            case 'server':
                $this->assertEquals(200, $row['headers']['status-code']);
                break;
        }

        return $data;
    }

    public function testEnforceTableAndRowPermissions(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'EnforceCollectionAndRowPermissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('EnforceCollectionAndRowPermissions', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $user = $this->getUser()['$id'];
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'enforceCollectionAndRowPermissions',
            'rowSecurity' => true,
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::create(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals($table['body']['name'], 'enforceCollectionAndRowPermissions');
        $this->assertEquals($table['body']['rowSecurity'], true);

        $tableId = $table['body']['$id'];

        sleep(2);

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'column',
            'size' => 64,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code'], 202);
        $this->assertEquals('column', $attribute['body']['key']);

        // wait for db to add column
        sleep(2);

        $index = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'columns' => [$attribute['body']['key']],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        // wait for db to add column
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'column' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'column' => 'one',
            ],
            'permissions' => [
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'column' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom('other'))),
                Permission::update(Role::user(ID::custom('other'))),
            ],
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        $rowsUser1 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the table so can get any row
        $this->assertEquals(3, $rowsUser1['body']['total']);
        $this->assertCount(3, $rowsUser1['body']['rows']);

        $row3GetWithCollectionRead = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row3['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the table so can get any row
        $this->assertEquals(200, $row3GetWithCollectionRead['headers']['status-code']);

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

        $row3GetWithRowRead = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row3['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no table permissions but has read permission for this row
        $this->assertEquals(200, $row3GetWithRowRead['headers']['status-code']);

        $row2GetFailure = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row2['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no table or row permissions for this row
        $this->assertEquals(404, $row2GetFailure['headers']['status-code']);

        $rowsUser2 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no table permissions but has read permission for one row
        $this->assertEquals(1, $rowsUser2['body']['total']);
        $this->assertCount(1, $rowsUser2['body']['rows']);
    }

    public function testEnforceTablePermissions(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
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
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'enforceCollectionPermissions',
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::create(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals($table['body']['name'], 'enforceCollectionPermissions');
        $this->assertEquals($table['body']['rowSecurity'], false);

        $tableId = $table['body']['$id'];

        $attribute = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'column',
            'size' => 64,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code'], 202);
        $this->assertEquals('column', $attribute['body']['key']);

        \sleep(2);

        $index = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'columns' => [$attribute['body']['key']],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        \sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'column' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user($user)),
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'column' => 'one',
            ],
            'permissions' => [
                Permission::update(Role::user($user)),
                Permission::delete(Role::user($user)),
            ]
        ]);

        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'column' => 'one',
            ],
            'permissions' => [
                Permission::read(Role::user(ID::custom('other2'))),
                Permission::update(Role::user(ID::custom('other2'))),
            ],
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        $rowsUser1 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the table so can get any row
        $this->assertEquals(3, $rowsUser1['body']['total']);
        $this->assertCount(3, $rowsUser1['body']['rows']);

        $row3GetWithCollectionRead = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row3['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the table so can get any row
        $this->assertEquals(200, $row3GetWithCollectionRead['headers']['status-code']);

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

        $row3GetWithRowRead = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row3['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // other2 has no table permissions and row permissions are disabled
        $this->assertEquals(404, $row3GetWithRowRead['headers']['status-code']);

        $rowsUser2 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // other2 has no table permissions and row permissions are disabled
        $this->assertEquals(401, $rowsUser2['headers']['status-code']);

        // Enable row permissions
        $this->client->call(CLient::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => $table['body']['name'],
            'rowSecurity' => true,
        ]);

        $rowsUser2 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no table permissions read access to one row
        $this->assertEquals(1, $rowsUser2['body']['total']);
        $this->assertCount(1, $rowsUser2['body']['rows']);
    }

    /**
     * @depends testDefaultPermissions
     */
    public function testUniqueIndexDuplicate(array $data): array
    {
        $databaseId = $data['databaseId'];
        $uniqueIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unique_title',
            'type' => 'unique',
            'columns' => ['title'],
        ]);

        $this->assertEquals(202, $uniqueIndex['headers']['status-code']);

        sleep(2);

        // test for failure
        $duplicate = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        // Test for exception when updating row to conflict
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $this->assertEquals(201, $row['headers']['status-code']);

        // Test for exception when updating row to conflict
        $duplicate = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['moviesId'] . '/rows', $headers, [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Creation Date Test',
                'releaseYear' => 2000
            ]
        ]);

        $this->assertEquals($row['body']['title'], 'Creation Date Test');

        $rowId = $row['body']['$id'];
        $createdAt = $row['body']['$createdAt'];
        $updatedAt = $row['body']['$updatedAt'];

        \sleep(1);

        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, $headers, [
            'data' => [
                'title' => 'Updated Date Test',
            ]
        ]);

        $updatedAtSecond = $row['body']['$updatedAt'];

        $this->assertEquals($row['body']['title'], 'Updated Date Test');
        $this->assertEquals($row['body']['$createdAt'], $createdAt);
        $this->assertNotEquals($row['body']['$updatedAt'], $updatedAt);

        \sleep(1);

        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['moviesId'] . '/rows/' . $rowId, $headers, [
            'data' => [
                'title' => 'Again Updated Date Test',
                '$createdAt' => '2022-08-01 13:09:23.040',
                '$updatedAt' => '2022-08-01 13:09:23.050'
            ]
        ]);

        if ($this->getSide() === 'client') {
            $this->assertEquals($row['body']['title'], 'Again Updated Date Test');
            $this->assertNotEquals($row['body']['$createdAt'], DateTime::formatTz('2022-08-01 13:09:23.040'));
            $this->assertNotEquals($row['body']['$updatedAt'], DateTime::formatTz('2022-08-01 13:09:23.050'));
        } else {
            $this->assertEquals($row['body']['$createdAt'], DateTime::formatTz('2022-08-01 13:09:23.040'));
            $this->assertEquals($row['body']['$updatedAt'], DateTime::formatTz('2022-08-01 13:09:23.050'));

        }

        return $data;
    }

    public function testUpdatePermissionsWithEmptyPayload(): array
    {
        // Create Database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Empty Permissions',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);

        $databaseId = $database['body']['$id'];

        // Create table
        $movies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::create(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        $moviesId = $movies['body']['$id'];

        // create column
        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/columns/string', array_merge([
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

        // add row
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Captain America',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $id = $row['body']['$id'];

        $this->assertEquals(201, $row['headers']['status-code']);
        $this->assertCount(3, $row['body']['$permissions']);
        $this->assertContains(Permission::read(Role::any()), $row['body']['$permissions']);
        $this->assertContains(Permission::update(Role::any()), $row['body']['$permissions']);
        $this->assertContains(Permission::delete(Role::any()), $row['body']['$permissions']);

        // Send only read permission
        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::user(ID::custom($this->getUser()['$id']))),
            ]
        ]);

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertCount(1, $row['body']['$permissions']);

        // Send only mutation permissions
        $row = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $moviesId . '/rows/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::update(Role::user(ID::custom($this->getUser()['$id']))),
                Permission::delete(Role::user(ID::custom($this->getUser()['$id']))),
            ],
        ]);

        if ($this->getSide() == 'server') {
            $this->assertEquals(200, $row['headers']['status-code']);
            $this->assertCount(2, $row['body']['$permissions']);
            $this->assertContains(Permission::update(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
            $this->assertContains(Permission::delete(Role::user($this->getUser()['$id'])), $row['body']['$permissions']);
        }

        // remove table
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $moviesId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return [];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testColumnBooleanDefault(array $data): void
    {
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Boolean'
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $tableId = $table['body']['$id'];

        $true = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'true',
            'required' => false,
            'default' => true
        ]);

        $this->assertEquals(202, $true['headers']['status-code']);

        $false = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/boolean', array_merge([
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

        $person = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'person',
            'name' => 'person',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $person['headers']['status-code']);

        $library = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'library',
            'name' => 'library',
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $library['headers']['status-code']);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fullName',
            'size' => 255,
            'required' => false,
        ]);

        sleep(1); // Wait for worker

        $relation = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => 'library',
            'type' => Database::RELATION_ONE_TO_ONE,
            'key' => 'library',
            'twoWay' => true,
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);

        sleep(1); // Wait for worker

        $libraryName = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $library['body']['$id'] . '/columns/string', array_merge([
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

        $columns = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/columns', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $columns['headers']['status-code']);
        $this->assertEquals(2, $columns['body']['total']);
        $columns = $columns['body']['columns'];
        $this->assertEquals('library', $columns[1]['relatedTable']);
        $this->assertEquals('oneToOne', $columns[1]['relationType']);
        $this->assertEquals(true, $columns[1]['twoWay']);
        $this->assertEquals('person', $columns[1]['twoWayKey']);
        $this->assertEquals(Database::RELATION_MUTATE_CASCADE, $columns[1]['onDelete']);

        $attribute = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$person['body']['$id']}/columns/library", array_merge([
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

        $person1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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
        $person2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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

        $this->assertEquals($person['body']['$id'], $person1['body']['$tableId']);
        $this->assertEquals($library['body']['$id'], $person1['body']['library']['$tableId']);

        $this->assertArrayNotHasKey('$table', $person1['body']);
        $this->assertArrayNotHasKey('$table', $person1['body']['library']);
        $this->assertArrayNotHasKey('$internalId', $person1['body']);
        $this->assertArrayNotHasKey('$internalId', $person1['body']['library']);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString(),
                Query::equal('library', ['library1'])->toString(),
            ],
        ]);

        $this->assertEquals(1, $rows['body']['total']);
        $this->assertEquals('Library 1', $rows['body']['rows'][0]['library']['libraryName']);
        $this->assertArrayHasKey('fullName', $rows['body']['rows'][0]);

        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['library.*'])->toString(),
                Query::equal('library.libraryName', ['Library 1'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(1, $rows['body']['total']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Library 1', $rows['body']['rows'][0]['library']['libraryName']);
        $this->assertEquals($person1['body']['$id'], $rows['body']['rows'][0]['$id']);

        $response = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/columns/library', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        sleep(2);

        $this->assertEquals(204, $response['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$person['body']['$id']}/columns/library", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(404, $attribute['headers']['status-code']);

        $person1 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $person['body']['$id'] . '/rows/' . $person1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertArrayNotHasKey('library', $person1['body']);

        //Test Deletion of related twoKey
        $columns = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $library['body']['$id'] . '/columns', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $columns['headers']['status-code']);
        $this->assertEquals(1, $columns['body']['total']);
        $this->assertEquals('libraryName', $columns['body']['columns'][0]['key']);

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
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $personCollection . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => 'library',
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'libraries',
            'twoWayKey' => 'person_one_to_many',
        ]);

        sleep(1);

        $libraryAttributesResponse = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $libraryCollection . '/columns', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertIsArray($libraryAttributesResponse['body']['columns']);
        $this->assertEquals(2, $libraryAttributesResponse['body']['total']);
        $this->assertEquals('person_one_to_many', $libraryAttributesResponse['body']['columns'][1]['key']);

        $libraryCollectionResponse = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $libraryCollection, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertIsArray($libraryCollectionResponse['body']['columns']);
        $this->assertCount(2, $libraryCollectionResponse['body']['columns']);

        $attribute = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$personCollection}/columns/libraries", array_merge([
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

        $person2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $personCollection . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => 'person10',
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

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $personCollection . '/rows/' . $person2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['*', 'libraries.*'])->toString()
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('$table', $response['body']);
        $this->assertArrayHasKey('libraries', $response['body']);
        $this->assertEquals(2, count($response['body']['libraries']));

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $libraryCollection . '/rows/library11', array_merge([
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

        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $personCollection . '/columns/libraries/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'onDelete' => Database::RELATION_MUTATE_CASCADE,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$personCollection}/columns/libraries", array_merge([
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

        // Create album table
        $albums = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Albums',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create album name column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $albums['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create artist table
        $artists = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Artists',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create artist name column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $artists['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create relationship
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $albums['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $artists['body']['$id'],
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
        $album = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $albums['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => 'album1',
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

        $album = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $albums['body']['$id'] . '/rows/album1', array_merge([
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

        $artist = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $artists['body']['$id'] . '/rows/' . $album['body']['artist']['$id'], array_merge([
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

        // Create sports table
        $sports = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Sports',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create sport name column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $sports['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create player table
        $players = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Players',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        // Create player name column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $players['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);

        // Create relationship
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $sports['body']['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $players['body']['$id'],
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
        $sport = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $sports['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => 'sport1',
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

        $sport = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $sports['body']['$id'] . '/rows/sport1', array_merge([
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

        $player = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $players['body']['$id'] . '/rows/' . $sport['body']['players'][0]['$id'], array_merge([
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
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['personCollection'] . '/rows', array_merge([
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
        $this->assertEquals(1, count($response['body']['rows']));
        $this->assertEquals('person10', $response['body']['rows'][0]['$id']);
        $this->assertEquals('Stevie Wonder', $response['body']['rows'][0]['fullName']);
        $this->assertEquals(2, count($response['body']['rows'][0]['libraries']));

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['personCollection'] . '/rows', array_merge([
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
        $this->assertEquals(2, count($response['body']['rows']));
        $this->assertEquals(null, $response['body']['rows'][0]['fullName']);
        $this->assertArrayNotHasKey("libraries", $response['body']['rows'][0]);
        $this->assertArrayHasKey('$databaseId', $response['body']['rows'][0]);
        $this->assertArrayHasKey('$tableId', $response['body']['rows'][0]);
    }

    /**
     * @depends testOneToManyRelationship
     */
    public function testSelectQueries(array $data): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['personCollection'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('fullName', ['Stevie Wonder'])->toString(),
                Query::select(['fullName'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('libraries', $response['body']['rows'][0]);
        $this->assertArrayHasKey('$databaseId', $response['body']['rows'][0]);
        $this->assertArrayHasKey('$tableId', $response['body']['rows'][0]);

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['personCollection'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['libraries.*', '$id'])->toString(),
            ],
        ]);
        $row = $response['body']['rows'][0];
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('libraries', $row);
        $this->assertArrayHasKey('$databaseId', $row);
        $this->assertArrayHasKey('$tableId', $row);

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['personCollection'] . '/rows/' . $row['$id'], array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
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
        $presidents = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'USA Presidents',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $presidents['headers']['status-code']);
        $this->assertEquals($presidents['body']['name'], 'USA Presidents');

        // Create Attributes
        $firstName = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $presidents['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'first_name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $firstName['headers']['status-code']);

        $lastName = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $presidents['body']['$id'] . '/columns/string', array_merge([
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

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $presidents['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'first_name' => 'Donald',
                'last_name' => 'Trump',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $presidents['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'first_name' => 'George',
                'last_name' => 'Bush',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $presidents['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'first_name' => 'Joe',
                'last_name' => 'Biden',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $presidents['body']['$id'] . '/rows',
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

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(2, $rows['body']['rows']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testNotContains(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'NotContains test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('NotContains test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $movies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        // Create Attributes
        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $title['headers']['status-code']);

        $genre = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'genre',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $genre['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Spider-Man: Homecoming',
                'genre' => 'Action',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'The Avengers',
                'genre' => 'Action',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Romantic Comedy',
                'genre' => 'Romance',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test notContains query - should return movies that don't contain "Spider" in title
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::select(['title', 'genre'])->toString(),
                    Query::notContains('title', ['Spider'])->toString(),
                    Query::limit(999)->toString(),
                    Query::offset(0)->toString()
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(2, $rows['body']['rows']);
        $this->assertEquals('The Avengers', $rows['body']['rows'][0]['title']);
        $this->assertEquals('Romantic Comedy', $rows['body']['rows'][1]['title']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testNotSearch(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'NotSearch test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('NotSearch test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $books = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Books',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $books['headers']['status-code']);
        $this->assertEquals($books['body']['name'], 'Books');

        // Create Attributes
        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $title['headers']['status-code']);

        $description = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 2048,
            'required' => true,
        ]);

        $this->assertEquals(202, $description['headers']['status-code']);

        \sleep(2);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'fts_description',
            'type' => Database::INDEX_FULLTEXT,
            'columns' => ['description'],
        ]);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Science Fiction Adventures',
                'description' => 'A thrilling journey through space and time',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Romance Novel',
                'description' => 'A love story set in modern times',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Mystery Thriller',
                'description' => 'A detective solves complex crimes',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test notSearch query - should return books that don't have "space" in the description
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $books['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::notSearch('description', 'space')->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(2, $rows['body']['rows']);
        $this->assertEquals('Romance Novel', $rows['body']['rows'][0]['title']);
        $this->assertEquals('Mystery Thriller', $rows['body']['rows'][1]['title']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testNotBetween(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'NotBetween test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('NotBetween test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $products = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Products',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $products['headers']['status-code']);
        $this->assertEquals($products['body']['name'], 'Products');

        // Create Attributes
        $name = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $name['headers']['status-code']);

        $price = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/columns/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'price',
            'required' => true,
        ]);

        $this->assertEquals(202, $price['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Cheap Product',
                'price' => 5.99,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Mid Product',
                'price' => 25.00,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Expensive Product',
                'price' => 150.00,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test notBetween query - should return products NOT priced between 10 and 50
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::notBetween('price', 10, 50)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(2, $rows['body']['rows']);
        $this->assertEquals('Cheap Product', $rows['body']['rows'][0]['name']);
        $this->assertEquals('Expensive Product', $rows['body']['rows'][1]['name']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testNotStartsWith(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'NotStartsWith test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('NotStartsWith test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $employees = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Employees',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $employees['headers']['status-code']);
        $this->assertEquals($employees['body']['name'], 'Employees');

        // Create Attributes
        $name = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $employees['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $name['headers']['status-code']);

        $department = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $employees['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'department',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $department['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $employees['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'John Smith',
                'department' => 'Engineering',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $employees['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Jane Doe',
                'department' => 'Marketing',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $employees['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Bob Johnson',
                'department' => 'Sales',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test notStartsWith query - should return employees whose names don't start with "John"
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $employees['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::notStartsWith('name', 'John')->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(2, $rows['body']['rows']);
        $this->assertEquals('Jane Doe', $rows['body']['rows'][0]['name']);
        $this->assertEquals('Bob Johnson', $rows['body']['rows'][1]['name']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testNotEndsWith(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'NotEndsWith test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('NotEndsWith test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $files = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Files',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $files['headers']['status-code']);
        $this->assertEquals($files['body']['name'], 'Files');

        // Create Attributes
        $filename = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $files['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'filename',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $filename['headers']['status-code']);

        $type = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $files['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'type',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $type['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $files['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'filename' => 'row.pdf',
                'type' => 'PDF',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $files['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'filename' => 'image.jpg',
                'type' => 'Image',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $files['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'filename' => 'presentation.pptx',
                'type' => 'Presentation',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test notEndsWith query - should return files that don't end with ".pdf"
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $files['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::notEndsWith('filename', '.pdf')->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(2, $rows['body']['rows']);
        $this->assertEquals('image.jpg', $rows['body']['rows'][0]['filename']);
        $this->assertEquals('presentation.pptx', $rows['body']['rows'][1]['filename']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testCreatedBefore(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CreatedBefore test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('CreatedBefore test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $posts = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Posts',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $posts['headers']['status-code']);
        $this->assertEquals($posts['body']['name'], 'Posts');

        // Create Attributes
        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $posts['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $title['headers']['status-code']);

        $content = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $posts['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'content',
            'size' => 512,
            'required' => true,
        ]);

        $this->assertEquals(202, $content['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $posts['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Old Post',
                'content' => 'This is an old post content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        // Sleep to ensure different creation times
        sleep(1);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $posts['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Recent Post',
                'content' => 'This is a recent post content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        // Get the creation time of the second post to use as boundary
        $secondPostCreatedAt = $row2['body']['$createdAt'];

        // Sleep again
        sleep(1);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $posts['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Newest Post',
                'content' => 'This is the newest post content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test createdBefore query - should return posts created before the second post
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $posts['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::createdBefore($secondPostCreatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Old Post', $rows['body']['rows'][0]['title']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testCreatedAfter(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CreatedAfter test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('CreatedAfter test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $events = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Events',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $events['headers']['status-code']);
        $this->assertEquals($events['body']['name'], 'Events');

        // Create Attributes
        $name = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $events['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $name['headers']['status-code']);

        $description = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $events['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 512,
            'required' => true,
        ]);

        $this->assertEquals(202, $description['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $events['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Early Event',
                'description' => 'This is an early event',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        // Sleep to ensure different creation times
        sleep(1);

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $events['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Middle Event',
                'description' => 'This is a middle event',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        // Get the creation time of the second event to use as boundary
        $secondEventCreatedAt = $row2['body']['$createdAt'];

        // Sleep again
        sleep(1);

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $events['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Latest Event',
                'description' => 'This is the latest event',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->assertEquals(201, $row3['headers']['status-code']);

        // Test createdAfter query - should return events created after the second event
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $events['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::createdAfter($secondEventCreatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Latest Event', $rows['body']['rows'][0]['name']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testCreatedBetween(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CreatedBetween test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('CreatedBetween test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $articles = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Articles',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $articles['headers']['status-code']);
        $this->assertEquals($articles['body']['name'], 'Articles');

        // Create Attributes
        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $title['headers']['status-code']);

        $content = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'content',
            'size' => 5000,
            'required' => true,
        ]);
        $this->assertEquals(202, $content['headers']['status-code']);

        // Wait for attributes to be available
        sleep(2);

        // Create first article
        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'First Article',
                'content' => 'This is the first article content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);
        $firstArticleCreatedAt = $row1['body']['$createdAt'];

        // Sleep to ensure different timestamps
        sleep(1);

        // Create second article
        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Second Article',
                'content' => 'This is the second article content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);
        $secondArticleCreatedAt = $row2['body']['$createdAt'];

        // Sleep again
        sleep(1);

        // Create third article
        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Third Article',
                'content' => 'This is the third article content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row3['headers']['status-code']);
        $thirdArticleCreatedAt = $row3['body']['$createdAt'];

        // Sleep again
        sleep(1);

        // Create fourth article
        $row4 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Fourth Article',
                'content' => 'This is the fourth article content',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row4['headers']['status-code']);

        // Test createdBetween query - should return articles created between first and third (inclusive)
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::createdBetween($firstArticleCreatedAt, $thirdArticleCreatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(3, $rows['body']['rows']);

        // Verify the returned articles are the correct ones
        $titles = array_column($rows['body']['rows'], 'title');
        $this->assertContains('First Article', $titles);
        $this->assertContains('Second Article', $titles);
        $this->assertContains('Third Article', $titles);
        $this->assertNotContains('Fourth Article', $titles);

        // Test createdBetween query - should return only the second article when using its timestamp for both bounds
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $articles['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::createdBetween($secondArticleCreatedAt, $secondArticleCreatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Second Article', $rows['body']['rows'][0]['title']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testUpdatedBefore(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'UpdatedBefore test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('UpdatedBefore test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $tasks = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Tasks',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $tasks['headers']['status-code']);
        $this->assertEquals($tasks['body']['name'], 'Tasks');

        // Create Attributes
        $title = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $title['headers']['status-code']);

        $status = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $status['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Task One',
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);
        $taskOneId = $row1['body']['$id'];

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Task Two',
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);
        $taskTwoId = $row2['body']['$id'];

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'title' => 'Task Three',
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row3['headers']['status-code']);
        $taskThreeId = $row3['body']['$id'];

        // Update first task
        sleep(1);
        $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows/' . $taskOneId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'completed',
            ]
        ]);

        // Update second task and get its updated time
        sleep(1);
        $updatedTaskTwo = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows/' . $taskTwoId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'in_progress',
            ]
        ]);
        $secondTaskUpdatedAt = $updatedTaskTwo['body']['$updatedAt'];

        // Update third task
        sleep(1);
        $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows/' . $taskThreeId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'review',
            ]
        ]);

        // Test updatedBefore query - should return tasks updated before the second task's update time
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $tasks['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::updatedBefore($secondTaskUpdatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Task One', $rows['body']['rows'][0]['title']);
        $this->assertEquals('completed', $rows['body']['rows'][0]['status']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testUpdatedAfter(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'UpdatedAfter test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('UpdatedAfter test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $orders = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Orders',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $orders['headers']['status-code']);
        $this->assertEquals($orders['body']['name'], 'Orders');

        // Create Attributes
        $orderNumber = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'orderNumber',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $orderNumber['headers']['status-code']);

        $status = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $status['headers']['status-code']);

        // Wait for worker
        sleep(2);

        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'orderNumber' => 'ORD-001',
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);
        $orderOneId = $row1['body']['$id'];

        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'orderNumber' => 'ORD-002',
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);
        $orderTwoId = $row2['body']['$id'];

        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'orderNumber' => 'ORD-003',
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row3['headers']['status-code']);
        $orderThreeId = $row3['body']['$id'];

        // Update first order
        sleep(1);
        $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows/' . $orderOneId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'processing',
            ]
        ]);

        // Update second order and get its updated time
        sleep(1);
        $updatedOrderTwo = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows/' . $orderTwoId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'shipped',
            ]
        ]);
        $secondOrderUpdatedAt = $updatedOrderTwo['body']['$updatedAt'];

        // Update third order
        sleep(1);
        $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows/' . $orderThreeId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'delivered',
            ]
        ]);

        // Test updatedAfter query - should return orders updated after the second order's update time
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $orders['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::updatedAfter($secondOrderUpdatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('ORD-003', $rows['body']['rows'][0]['orderNumber']);
        $this->assertEquals('delivered', $rows['body']['rows'][0]['status']);
    }

    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testUpdatedBetween(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'UpdatedBetween test'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('UpdatedBetween test', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $products = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Products',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $products['headers']['status-code']);
        $this->assertEquals($products['body']['name'], 'Products');

        // Create Attributes
        $name = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $name['headers']['status-code']);

        $price = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/columns/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'price',
            'required' => true,
        ]);
        $this->assertEquals(202, $price['headers']['status-code']);

        // Wait for attributes to be available
        sleep(2);

        // Create first product
        $row1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Product A',
                'price' => 99.99,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row1['headers']['status-code']);

        // Sleep to ensure different timestamps
        sleep(1);

        // Create second product
        $row2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Product B',
                'price' => 149.99,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row2['headers']['status-code']);

        // Sleep again
        sleep(1);

        // Create third product
        $row3 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Product C',
                'price' => 199.99,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row3['headers']['status-code']);

        // Sleep again
        sleep(1);

        // Create fourth product
        $row4 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Product D',
                'price' => 249.99,
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row4['headers']['status-code']);

        // Now update products in sequence to get different updatedAt timestamps
        sleep(1);

        // Update first product
        $update1 = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows/' . $row1['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'price' => 89.99,
            ]
        ]);
        $this->assertEquals(200, $update1['headers']['status-code']);
        $firstProductUpdatedAt = $update1['body']['$updatedAt'];

        sleep(1);

        // Update second product
        $update2 = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows/' . $row2['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'price' => 139.99,
            ]
        ]);
        $this->assertEquals(200, $update2['headers']['status-code']);
        $secondProductUpdatedAt = $update2['body']['$updatedAt'];

        sleep(1);

        // Update third product
        $update3 = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows/' . $row3['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'price' => 189.99,
            ]
        ]);
        $this->assertEquals(200, $update3['headers']['status-code']);
        $thirdProductUpdatedAt = $update3['body']['$updatedAt'];

        sleep(1);

        // Update fourth product
        $update4 = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows/' . $row4['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'price' => 239.99,
            ]
        ]);
        $this->assertEquals(200, $update4['headers']['status-code']);

        // Test updatedBetween query - should return products updated between first and third (inclusive)
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::updatedBetween($firstProductUpdatedAt, $thirdProductUpdatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(3, $rows['body']['rows']);

        // Verify the returned products are the correct ones
        $names = array_column($rows['body']['rows'], 'name');
        $this->assertContains('Product A', $names);
        $this->assertContains('Product B', $names);
        $this->assertContains('Product C', $names);
        $this->assertNotContains('Product D', $names);

        // Test updatedBetween query - should return only the second product when using its timestamp for both bounds
        $rows = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $products['body']['$id'] . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::updatedBetween($secondProductUpdatedAt, $secondProductUpdatedAt)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertCount(1, $rows['body']['rows']);
        $this->assertEquals('Product B', $rows['body']['rows'][0]['name']);
        $this->assertEquals(139.99, $rows['body']['rows'][0]['price']);
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

        $table1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Collection1',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $table2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Collection2',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $table1 = $table1['body']['$id'];
        $table2 = $table2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1 . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => '49',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table2 . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => '49',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1 . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2,
            'type' => Database::RELATION_ONE_TO_MANY,
            'twoWay' => true,
            'key' => 'collection2'
        ]);

        sleep(1);

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $table1 . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Row 1',
                'collection2' => [
                    [
                        'name' => 'Row 2',
                    ],
                ],
            ],
        ]);

        $update = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $table1 . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Row 1 Updated',
            ],
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
    }

    /**
     * @depends testCreateDatabase
     */
    public function testTimeout(array $data): void
    {
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $data['databaseId'] . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Slow Queries',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $data = [
            '$id' => $table['body']['$id'],
            'databaseId' => $table['body']['databaseId']
        ];

        $longtext = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/columns/string', array_merge([
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
            $this->client->call(Client::METHOD_POST, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'rowId' => ID::unique(),
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

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timeout' => 1,
        ], $this->getHeaders()), [
            'queries' => [
                Query::notEqual('longtext', 'appwrite')->toString(),
            ],
        ]);

        $this->assertEquals(408, $response['headers']['status-code']);
    }

    /**
     * @throws \Exception
     */
    public function testIncrementColumn(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CounterDatabase'
        ]);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'CounterCollection',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);
        $tableId = $table['body']['$id'];

        // Add integer column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'count',
            'required' => true,
        ]);

        \sleep(3);

        // Create row with initial count = 5
        $doc = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'count' => 5
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $doc['headers']['status-code']);

        $rowId = $doc['body']['$id'];

        // Increment by default 1
        $inc = $this->client->call(Client::METHOD_PATCH, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(200, $inc['headers']['status-code']);
        $this->assertEquals($tableId, $inc['body']['$tableId']);
        $this->assertEquals(6, $inc['body']['count']);

        // Verify count = 6
        $get = $this->client->call(Client::METHOD_GET, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(6, $get['body']['count']);

        // Increment by custom value 4
        $inc2 = $this->client->call(Client::METHOD_PATCH, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 4
        ]);
        $this->assertEquals(200, $inc2['headers']['status-code']);
        $this->assertEquals(10, $inc2['body']['count']);

        $get2 = $this->client->call(Client::METHOD_GET, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(10, $get2['body']['count']);

        // Test max limit exceeded
        $err = $this->client->call(Client::METHOD_PATCH, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['max' => 8]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test column not found
        $notFound = $this->client->call(Client::METHOD_PATCH, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId/unknown/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(404, $notFound['headers']['status-code']);

        // Test decrement with value 0
        $inc3 = $this->client->call(Client::METHOD_PATCH, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 0
        ]);
        $this->assertEquals(400, $inc3['headers']['status-code']);
    }

    public function testDecrementColumn(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CounterDatabase'
        ]);

        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'CounterCollection',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Add integer column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'count',
            'required' => true,
        ]);

        \sleep(2);

        // Create row with initial count = 10
        $doc = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => ['count' => 10],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $rowId = $doc['body']['$id'];

        // Decrement by default 1 (count = 10 -> 9)
        $dec = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(200, $dec['headers']['status-code']);
        $this->assertEquals(9, $dec['body']['count']);
        $this->assertEquals($tableId, $dec['body']['$tableId']);

        $get = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(9, $get['body']['count']);

        // Decrement by custom value 3 (count 9 -> 6)
        $dec2 = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 3
        ]);
        $this->assertEquals(200, $dec2['headers']['status-code']);
        $this->assertEquals(6, $dec2['body']['count']);

        $get2 = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(6, $get2['body']['count']);

        // Test min limit exceeded
        $err = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['min' => 7]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test type error on non-numeric column
        $typeErr = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['value' => 'not-a-number']);
        $this->assertEquals(400, $typeErr['headers']['status-code']);

        // Test decrement with value 0
        $inc3 = $this->client->call(Client::METHOD_PATCH, "/tablesdb/$databaseId/tables/$tableId/rows/$rowId/count/decrement", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 0
        ]);
        $this->assertEquals(400, $inc3['headers']['status-code']);
    }

    public function testSpatialPointColumns(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Point Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create table with spatial and non-spatial columns
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Spatial Point Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Create string column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        // Create point column
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'location',
            'required' => true,
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(2);

        // Create row with point column
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Test Location',
                'location' => [40.7128, -74.0060]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([40.7128, -74.0060], $response['body']['location']);
        $rowId = $response['body']['$id'];

        // Read row with point column
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7128, -74.0060], $response['body']['location']);

        // Update row with new point coordinates
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'location' => [40.7589, -73.9851]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([40.7589, -73.9851], $response['body']['location']);

        // Upsert row with point column
        $response = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Upserted Location',
                'location' => [34.0522, -80]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([34.0522, -80], $response['body']['location']);

        // Create row without permissions (should fail)
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Unauthorized Location',
                'location' => [0, 0]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialLineColumns(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Line Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create table with spatial and non-spatial columns
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Spatial Line Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Create integer column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'distance',
            'required' => true,
        ]);

        // Create line column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => true,
        ]);

        sleep(2);

        // Create row with line column
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'distance' => 100,
                'route' => [[40.7128, -74.0060], [40.7589, -73.9851]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851]], $response['body']['route']);
        $rowId = $response['body']['$id'];

        // Read row
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851]], $response['body']['route']);

        // Update row
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'route' => [[40.7128, -74.0060], [40.7589, -73.9851], [40.7505, -73.9934]]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[40.7128, -74.0060], [40.7589, -73.9851], [40.7505, -73.9934]], $response['body']['route']);

        // Upsert row with line column
        $response = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'distance' => 200,
                'route' => [[34.0522, -80], [34.0736, -80]]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[34.0522, -80], [34.0736, -80]], $response['body']['route']);

        // Delete row
        $response = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);

        // Verify row is deleted
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(404, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialPolygonColumns(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Polygon Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create table with spatial and non-spatial columns
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Spatial Polygon Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Create boolean column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'active',
            'required' => true,
        ]);

        // Create polygon column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        sleep(2);

        // Create row with polygon column
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'active' => true,
                'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $response['body']['area']);
        $rowId = $response['body']['$id'];

        // Read row
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7128, -74.0060]]], $response['body']['area']);

        // Update row with new polygon
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'area' => [[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7505, -73.9934], [40.7128, -74.0060]]]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[40.7128, -74.0060], [40.7589, -74.0060], [40.7589, -73.9851], [40.7128, -73.9851], [40.7505, -73.9934], [40.7128, -74.0060]]], $response['body']['area']);

        // Upsert row with polygon column
        $response = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'active' => false,
                'area' => [[[34.0522, -80], [34.0736, -80], [34.0736, -80], [34.0522, -80], [34.0522, -80]]]
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([[[34.0522, -80], [34.0736, -80], [34.0736, -80], [34.0522, -80], [34.0522, -80]]], $response['body']['area']);

        // Create row missing required polygon (should fail)
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'active' => true
            ]
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialColumnsMixedTable(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Mixed Spatial Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create table with multiple spatial and non-spatial columns
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Mixed Spatial Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Create multiple columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'center',
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boundary',
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'coverage',
            'required' => true,
        ]);

        sleep(3);

        // Create row with all spatial columns
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
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
        $rowId = $response['body']['$id'];

        // Update row with new spatial data
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId, array_merge([
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

        // Create row with minimal required columns
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Minimal Location',
                'center' => [0, 0],
                'coverage' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['center']);

        // Permission validation - create without user context
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Unauthorized Location',
                'center' => [0, 0],
                'coverage' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]
            ]
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialQuery(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Query Test Database'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $databaseId = $database['body']['$id'];

        // Create table with spatial columns
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Spatial Query Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create string column
        $nameColumn = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $nameColumn['headers']['status-code']);

        // Create point column
        $pointColumn = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'pointAttr',
            'required' => true,
        ]);
        $this->assertEquals(202, $pointColumn['headers']['status-code']);

        // Create line column
        $lineColumn = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lineAttr',
            'required' => true,
        ]);
        $this->assertEquals(202, $lineColumn['headers']['status-code']);

        // Create polygon column
        $polygonColumn = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'polyAttr',
            'required' => true,
        ]);
        $this->assertEquals(202, $polygonColumn['headers']['status-code']);

        // Wait for columns to be created
        sleep(2);

        // Create test rows with spatial data
        $rows = [
            [
                '$id' => 'row1',
                'name' => 'Test Row 1',
                'pointAttr' => [6.0, 6.0],
                'lineAttr' => [[1.0, 1.0], [1.1,1.1] , [2.0, 2.0]],
                'polyAttr' => [[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]]]
            ],
            [
                '$id' => 'row2',
                'name' => 'Test Row 2',
                'pointAttr' => [7.0, 6.0],
                'lineAttr' => [[10.0, 10.0], [20.0, 20.0]],
                'polyAttr' => [[[20.0, 20.0], [30.0, 20.0], [30.0, 30.0], [20.0, 30.0], [20.0, 20.0]]]
            ],
            [
                '$id' => 'row3',
                'name' => 'Test Row 3',
                'pointAttr' => [25.0, 25.0],
                'lineAttr' => [[25.0, 25.0], [35.0, 35.0]],
                'polyAttr' => [[[40.0, 40.0], [50.0, 40.0], [50.0, 50.0], [40.0, 50.0], [40.0, 40.0]]]
            ]
        ];

        foreach ($rows as $r) {
            $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'rowId' => $r['$id'],
                'data' => [
                    'name' => $r['name'],
                    'pointAttr' => $r['pointAttr'],
                    'lineAttr' => $r['lineAttr'],
                    'polyAttr' => $r['polyAttr']
                ]
            ]);
            $this->assertEquals(201, $response['headers']['status-code']);
        }

        // Equality on non-spatial column (name)
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('name', ['Test Row 1'])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['rows']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // Polygon column queries
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('polyAttr', [[[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]]]])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['rows']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // Not equal queries
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notEqual('pointAttr', [[6.0, 6.0]])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['rows']);

        // contains on line (point on line)
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::contains('lineAttr', [[1.1, 1.1]])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['rows']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // notContains on polygon (point outside all polygons)
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notContains('polyAttr', [[15.0, 15.0]])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // intersects on polygon (point inside row1 polygon)
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::intersects('polyAttr', [5.0, 5.0])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // notIntersects on polygon (point outside all polygons)
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notIntersects('polyAttr', [60.0, 60.0])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // overlaps on polygon (polygon overlapping row1)
        $overlapPoly = [[[5.0, 5.0], [12.0, 5.0], [12.0, 12.0], [5.0, 12.0], [5.0, 5.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::overlaps('polyAttr', $overlapPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // notOverlaps on polygon (polygon that overlaps none)
        $noOverlapPoly = [[[60.0, 60.0], [70.0, 60.0], [70.0, 70.0], [60.0, 70.0], [60.0, 60.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notOverlaps('polyAttr', $noOverlapPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // distance (equals) on point
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceEqual('pointAttr', [6.0, 6.0], 1.0)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('row2', $response['body']['rows'][0]['$id']);

        // notDistance (outside radius) on point
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceNotEqual('pointAttr', [6.0, 6.0], 1.0)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        // distanceGreaterThan
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceGreaterThan('pointAttr', [6.0, 6.0], 5.0)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        // distanceLessThan
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::distanceLessThan('pointAttr', [6.0, 6.0], 0.5)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        // crosses on line (query line crosses row1 line)
        $crossLine = [[1.0, 2.0], [2.0, 1.0]];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::crosses('lineAttr', $crossLine)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // notCrosses on line (query line does not cross any stored lines)
        $nonCrossLine = [[0.0, 1.0], [0.0, 2.0]];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notCrosses('lineAttr', $nonCrossLine)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // touches on polygon (query polygon touches row1 polygon at corner)
        $touchPoly = [[[10.0, 10.0], [20.0, 10.0], [20.0, 20.0], [10.0, 20.0], [10.0, 10.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::touches('polyAttr', $touchPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals('row1', $response['body']['rows'][0]['$id']);

        // notTouches on polygon (polygon far away should not touch)
        $farPoly = [[[60.0, 60.0], [70.0, 60.0], [70.0, 70.0], [60.0, 70.0], [60.0, 60.0]]];
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::notTouches('polyAttr', $farPoly)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, $response['body']['total']);

        // Select specific columns
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::select(['name', 'pointAttr'])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['rows']);
        foreach ($response['body']['rows'] as $doc) {
            $this->assertArrayHasKey('name', $doc);
            $this->assertArrayHasKey('pointAttr', $doc);
            $this->assertArrayNotHasKey('lineAttr', $doc);
            $this->assertArrayNotHasKey('polyAttr', $doc);
        }

        // Order by name
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::orderAsc('name')->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['rows']);
        $this->assertEquals('Test Row 1', $response['body']['rows'][0]['name']);
        $this->assertEquals('Test Row 2', $response['body']['rows'][1]['name']);
        $this->assertEquals('Test Row 3', $response['body']['rows'][2]['name']);

        // Limit results
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(2)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['rows']);

        // Offset results
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::offset(1)->toString(), Query::limit(2)->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['rows']);

        // Complex query with multiple conditions
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
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
        $this->assertCount(1, $response['body']['rows']);
        $this->assertEquals('Test Row 1', $response['body']['rows'][0]['name']);

        // Query with no results
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('name', ['Non-existent Row'])->toString()]
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['rows']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialIndex(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Index Test DB'
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'SpatialIdx',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create spatial columns: one required, one optional
        $reqPoint = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'pRequired',
            'required' => true,
        ]);
        $this->assertEquals(202, $reqPoint['headers']['status-code']);

        $optPoint = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'pOptional',
            'required' => false,
        ]);
        $this->assertEquals(202, $optPoint['headers']['status-code']);

        // Ensure columns are available
        sleep(2);

        // Create index on required spatial column (should succeed)
        $okIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_required_point',
            'type' => Database::INDEX_SPATIAL,
            'columns' => ['pRequired'],
        ]);
        $this->assertEquals(202, $okIndex['headers']['status-code']);

        // Create index on optional spatial column (should fail in case of mariadb)
        $badIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_optional_point',
            'type' => Database::INDEX_SPATIAL,
            'columns' => ['pOptional'],
        ]);
        $this->assertEquals(400, $badIndex['headers']['status-code']);

        // making it required to create index on it
        $updated = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point/'.'pOptional', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => null
        ]);
        $this->assertEquals(200, $updated['headers']['status-code']);

        sleep(2);

        $retriedIndex = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'idx_optional_point',
            'type' => Database::INDEX_SPATIAL,
            'columns' => ['pOptional'],
        ]);
        $this->assertEquals(202, $retriedIndex['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testUpdateSpatialColumns(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Update Spatial Columns Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        // Create table with spatial columns
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Update Spatial Columns Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $tableId = $table['body']['$id'];

        // Create string column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        // Create point column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'location',
            'required' => true,
        ]);

        // Create line column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => false,
        ]);

        // Create polygon column
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        sleep(2);

        // Test 1: Update point column - change required status
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point/location', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(false, $response['body']['required']);

        // Test 2: Update line column - change required status and add default value
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line/route', array_merge([
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

        // Test 3: Update polygon column - change key name
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon/area', array_merge([
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

        // Test 4: Update point column - add default value
        $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point/location', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => [0, 0],
            'required' => false
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['default']);

        // Test 5: Verify column updates by creating a row
        $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'Test Location',
                'coverage' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]]
            ]
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals([0, 0], $response['body']['location']); // Should use default value
        $this->assertEquals([[0, 0], [1, 1]], $response['body']['route']); // Should use default value

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }
    public function testSpatialDistanceInMeter(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];

        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Distance Meters Database'
        ]);
        $databaseId = $database['body']['$id'];

        // Create table
        $table = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables", $headers, [
            'tableId' => ID::unique(),
            'name' => 'Spatial Distance Meters Table',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $tableId = $table['body']['$id'];

        // Create point column
        $resp = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/columns/point", $headers, [
            'key' => 'loc',
            'required' => true,
        ]);
        $this->assertEquals(202, $resp['headers']['status-code']);

        sleep(2);

        // Create spatial index
        $indexResp = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/indexes", $headers, [
            'key' => 'idx_loc',
            'type' => Database::INDEX_SPATIAL,
            'columns' => ['loc'],
        ]);
        $this->assertEquals(202, $indexResp['headers']['status-code']);


        // Insert two points ~1km apart
        $points = [
            'p0' => [0.0000, 0.0000],
            'p1' => [0.0090, 0.0000]
        ];

        foreach ($points as $id => $loc) {
            $rowResp = $this->client->call(Client::METHOD_POST, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", $headers, [
                'rowId' => $id,
                'data' => ['loc' => $loc]
            ]);
            $this->assertEquals(201, $rowResp['headers']['status-code']);
        }

        // Queries
        $queries = [
            'within1_5km' => Query::distanceLessThan('loc', [0.0, 0.0], 1500, true),
            'within500m' => Query::distanceLessThan('loc', [0.0, 0.0], 500, true),
            'greater500m' => Query::distanceGreaterThan('loc', [0.0, 0.0], 500, true),
            'equal0m' => Query::distanceEqual('loc', [0.0, 0.0], 0, true),
            'notEqual0m' => Query::distanceNotEqual('loc', [0.0, 0.0], 0, true),
        ];

        // Assertions
        $results = [
            'within1_5km' => 2,
            'within500m' => 1,
            'greater500m' => 1,
            'equal0m' => 'p0',
            'notEqual0m' => 'p1'
        ];

        foreach ($queries as $key => $query) {
            $resp = $this->client->call(Client::METHOD_GET, "/tablesdb/{$databaseId}/tables/{$tableId}/rows", $headers, [
                'queries' => [$query->toString()]
            ]);
            $this->assertEquals(200, $resp['headers']['status-code']);
            if (is_int($results[$key])) {
                $this->assertCount($results[$key], $resp['body']['rows']);
            } else {
                $this->assertEquals($results[$key], $resp['body']['rows'][0]['$id']);
            }
        }

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, "/tablesdb/{$databaseId}/tables/{$tableId}", $headers);
        $this->client->call(Client::METHOD_DELETE, "/tablesdb/{$databaseId}", $headers);
    }

    public function testSpatialColCreateOnExistingData(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Distance Meters Database'
        ]);

        $databaseId = $database['body']['$id'];

        $tableId = ID::unique();
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => $tableId,
            'name' => 'spatial-test',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $description = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
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

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'description' => 'description'
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);

        $point = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'loc',
            'required' => true,
        ]);

        $this->assertEquals(400, $point['headers']['status-code']);

        $point = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'loc',
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(202, $point['headers']['status-code']);

        $line = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => true,
        ]);

        $this->assertEquals(400, $line['headers']['status-code']);

        $line = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'route',
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(202, $line['headers']['status-code']);

        $poly = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => true,
        ]);

        $this->assertEquals(400, $poly['headers']['status-code']);

        $poly = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial With Defaults Database'
        ]);

        $databaseId = $database['body']['$id'];

        $tableId = ID::unique();
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => $tableId,
            'name' => 'spatial-test-defaults',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $description = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
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

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'description' => 'description'
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);

        // Test point with default value
        $point = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/point', array_merge([
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
        $line = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/line', array_merge([
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
        $poly = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => false,
            'default' => [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]]
        ]);

        $this->assertEquals(202, $poly['headers']['status-code']);

        // Wait for columns to be available
        sleep(2);

        // Create a new row without spatial data to test default values
        $newRow = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'description' => 'test default values'
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $newRow['headers']['status-code']);

        $newRowId = $newRow['body']['$id'];

        // Fetch the row to verify default values are applied
        $fetchedRow = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $newRowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $fetchedRow['headers']['status-code']);

        // Verify default values are applied
        $this->assertEquals([0.0, 0.0], $fetchedRow['body']['loc']);
        $this->assertEquals([[0.0, 0.0], [1.0, 1.0]], $fetchedRow['body']['route']);
        $this->assertEquals([[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]], $fetchedRow['body']['area']);
    }

}
