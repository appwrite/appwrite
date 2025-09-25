<?php

namespace Tests\E2E\Services\Databases\DocumentsDB;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait DatabasesBase
{
    public function testCreateDatabase(): array
    {
        /**
         * Test for SUCCESS
         */
            $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
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
            $this->assertEquals('documentsdb', $database['body']['type']);

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
        $movies = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $actors = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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
            '/documentsdb/console/collections/' . $data['moviesId'] . '/documents',
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
            '/documentsdb/console/collections/' . $data['moviesId'] . '/documents',
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
        $response = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
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
            $responseCreateDocument = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

            $responseListDocument = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(404, $responseListDocument['headers']['status-code']);

            $responseGetDocument = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/someID', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(404, $responseGetDocument['headers']['status-code']);
        }

        $response = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
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
    public function testCreateIndexes(array $data): array
    {
        $databaseId = $data['databaseId'];

        $titleIndex = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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

        $releaseYearIndex = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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

        $releaseWithDate1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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

        $releaseWithDate2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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
        $fulltextReleaseYear = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYearDated',
            'type' => 'fulltext',
            'attributes' => ['releaseYear'],
        ]);

        $this->assertEquals(409, $fulltextReleaseYear['headers']['status-code']);
        $this->assertEquals($fulltextReleaseYear['body']['message'], 'Index with the requested key already exists. Try again with a different key.');

        $noAttributes = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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

        $duplicates = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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

        $tooLong = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'tooLong',
            'type' => 'key',
            'attributes' => ['description', 'tagline'],
        ]);
        // no errors in documentsdb
        $this->assertEquals(202, $tooLong['headers']['status-code']);

        $fulltextArray = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'ft',
            'type' => 'fulltext',
            'attributes' => ['actors'],
        ]);

        $this->assertEquals(202, $fulltextArray['headers']['status-code']);

        $actorsArray = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-actors',
            'type' => 'key',
            'attributes' => ['actors'],
        ]);

        $this->assertEquals(202, $actorsArray['headers']['status-code']);

        $twoLevelsArray = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-ip-actors',
            'type' => 'key',
            'attributes' => ['releaseYear', 'actors'], // 2 levels
            'orders' => ['DESC', 'DESC'],
        ]);

        $this->assertEquals(202, $twoLevelsArray['headers']['status-code']);

        $unknown = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'index-unknown',
            'type' => 'key',
            'attributes' => ['Unknown'],
        ]);

        $this->assertEquals(202, $unknown['headers']['status-code']);

        $index1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'integers-order',
            'type' => 'key',
            'attributes' => ['integers'], // array attribute
            'orders' => ['DESC'], // Check order is removed in API
        ]);

        $this->assertEquals(202, $index1['headers']['status-code']);

        $index2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'integers-size',
            'type' => 'key',
            'attributes' => ['integers'], // array attribute
        ]);

        $this->assertEquals(202, $index2['headers']['status-code']);

        /**
         * Create Indexes by worker
         */
        sleep(2);

        $movies = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertIsArray($movies['body']['indexes']);
        $this->assertCount(11, $movies['body']['indexes']);
        $this->assertEquals($titleIndex['body']['key'], $movies['body']['indexes'][0]['key']);
        $this->assertEquals($releaseYearIndex['body']['key'], $movies['body']['indexes'][1]['key']);
        $this->assertEquals($releaseWithDate1['body']['key'], $movies['body']['indexes'][2]['key']);
        $this->assertEquals($releaseWithDate2['body']['key'], $movies['body']['indexes'][3]['key']);

        $this->assertEventually(function () use ($databaseId, $data) {
            $movies = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'], array_merge([
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
        $create = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
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
        $index = $this->client->call(Client::METHOD_GET, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes/lengthTestIndex", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $index['headers']['status-code']);
        $this->assertEquals('lengthTestIndex', $index['body']['key']);
        $this->assertEquals([128, 200], $index['body']['lengths']);

        // Test case for lengths array overriding
        // set a length for an array attribute, it should get overriden with Database::ARRAY_INDEX_LENGTH
        $create = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'lengthOverrideTestIndex',
            'type' => 'key',
            'attributes' => ['actors'],
            'lengths' => [120]
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        $index = $this->client->call(Client::METHOD_GET, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes/lengthOverrideTestIndex", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals([Database::ARRAY_INDEX_LENGTH], $index['body']['lengths']);

        // Test case for count of lengths greater than attributes (should throw 400)
        $create = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
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
        $create = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
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
        $create = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
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
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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
        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document3 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document4 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Thor: Ragnarok', $document['body']['title']);

        /**
         * Resubmit same document, nothing to update
         */
        $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

        $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals('Thor: Love and Thunder', $document['body']['title']);

        // removing permission to read and delete
        $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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
        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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
        $document = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        // simulating for the client
        // the document should not be allowed to be deleted as needed downward
        if ($this->getSide() === 'client') {
            $this->assertEquals(401, $document['headers']['status-code']);
        }
        // giving the delete permission
        $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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
        $document = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $document['headers']['status-code']);

        // relationship behaviour
        $person = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $library = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        // upserting values
        $documentId = ID::unique();
        $person1 = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/'.$documentId, array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
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


        $person1 = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/'.$documentId, array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
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
        $person1 = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/'.ID::unique(), array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select(['fullName', 'library.*'])->toString()
            ],
        ]);
        $this->assertEquals(2, $documents['body']['total']);

        // test without passing permissions
        $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $document['headers']['status-code']);

        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleteResponse['headers']['status-code']);

        if ($this->getSide() === 'client') {
            // Skipped on server side: Creating a document with no permissions results in an empty permissions array, whereas on client side it assigns permissions to the current user

            // test without passing permissions
            $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

            $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(200, $document['headers']['status-code']);

            // updating the created doc
            $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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
            $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(401, $deleteResponse['headers']['status-code']);

            // giving the delete permission
            $document = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
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

            $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()));

            $this->assertEquals(204, $deleteResponse['headers']['status-code']);

            // upsertion for the related document without passing permissions
            // data should get added
            $newPersonId = ID::unique();
            $personNoPerm = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $newPersonId, array_merge([
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
            $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents', array_merge([
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
            $library3 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $library['body']['$id'] . '/documents/library3', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $library3['headers']['status-code']);
            $this->assertEquals('Library 3', $library3['body']['libraryName']);
            $this->assertArrayHasKey('$permissions', $library3['body']);
            $this->assertCount(3, $library3['body']['$permissions']);
            $this->assertNotEmpty($library3['body']['$permissions']);

            // Readonly attributes are ignored
            $personNoPerm = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $newPersonId, array_merge([
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

            $upserted = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $person['body']['$id'] . '/documents/' . $newPersonId, array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        // creating a dummy doc with null description
        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $documentsPaginated = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $document1['body']['$id'], array_merge([
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
            $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $document['$collectionId'] . '/documents/' . $document['$id'], array_merge([
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

        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $document['$collectionId'] . '/documents/' . $document['$id'], array_merge([
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
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $document['$collectionId'] . '/documents/' . $document['$id'], array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals('Captain America', $base['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['documents'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['documents'][2]['title']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertEquals('Captain America', $base['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['documents'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['documents'][2]['title']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                '{"method":"contains","attribute":"title","values":[bad]}'
            ],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);
        $this->assertEquals('Invalid query: Syntax error', $documents['body']['message']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('title', ['spi'])->toString(), // like query
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(2, $documents['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('releaseYear', [1944])->toString(),
            ],
        ]);

        $this->assertCount(1, $documents['body']['documents']);
        $this->assertEquals('Captain America', $documents['body']['documents'][0]['title']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('$createdAt', '1976-06-12')->toString(),
            ],
        ]);

        $this->assertCount(3, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('$createdAt', '1976-06-12')->toString(),
            ],
        ]);

        $this->assertCount(0, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('actors', ['Tom Holland', 'Samuel Jackson'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(3, $documents['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::contains('actors', ['Tom'])->toString(), // Full-match not like
            ],
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::greaterThan('birthDay', '16/01/2024 12:00:00AM')->toString(),
            ],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);
        $this->assertEquals('Invalid query: Query value is invalid for attribute "birthDay"', $documents['body']['message']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        // $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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

        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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

    /**
     * @depends testCreateDocument
     */
    public function testDeleteDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $document['headers']['status-code']);

        $document = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $document['headers']['status-code']);

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $document['headers']['status-code']);

        return $data;
    }

    public function testInvalidDocumentStructure(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $collection = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), []);
        // no default values in documentsdb
        $this->assertCount(0, $collection['body']['attributes']);

        /**
         * Test for successful validation
         */

        $goodEmail = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $goodEnum = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $goodIp = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $goodUrl = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $goodRange = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $goodFloatRange = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $goodProbability = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $notTooHigh = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $notTooLow = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
    }

    /**
     * @depends testDeleteDocument
     */
    public function testDefaultPermissions(array $data): array
    {
        $databaseId = $data['databaseId'];
        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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

        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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

        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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

        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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
        $document = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $index = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'attributes' => ['attributes'],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        // wait for db to add attribute
        sleep(2);

        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $document3 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
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

        $documentsUser1 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the collection so can get any document
        $this->assertEquals(3, $documentsUser1['body']['total']);
        $this->assertCount(3, $documentsUser1['body']['documents']);

        $document3GetWithCollectionRead = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], array_merge([
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

        $document3GetWithDocumentRead = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no collection permissions but has read permission for this document
        $this->assertEquals(200, $document3GetWithDocumentRead['headers']['status-code']);

        $document2GetFailure = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document2['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // Current user has no collection or document permissions for this document
        $this->assertEquals(404, $document2GetFailure['headers']['status-code']);

        $documentsUser2 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
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
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $index = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'attributes' => ['attributes'],
        ]);

        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        \sleep(2);

        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $document3 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
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

        $documentsUser1 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        // Current user has read permission on the collection so can get any document
        $this->assertEquals(3, $documentsUser1['body']['total']);
        $this->assertCount(3, $documentsUser1['body']['documents']);

        $document3GetWithCollectionRead = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], array_merge([
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

        $document3GetWithDocumentRead = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document3['body']['$id'], [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // other2 has no collection permissions and document permissions are disabled
        $this->assertEquals(404, $document3GetWithDocumentRead['headers']['status-code']);

        $documentsUser2 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session2,
        ]);

        // other2 has no collection permissions and document permissions are disabled
        $this->assertEquals(401, $documentsUser2['headers']['status-code']);

        // Enable document permissions
        $collection = $this->client->call(CLient::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => $collection['body']['name'],
            'documentSecurity' => true,
        ]);

        $documentsUser2 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', [
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
        $uniqueIndex = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/indexes', array_merge([
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
        $duplicate = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents', array_merge([
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
        $duplicate = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $data['moviesId'] . '/documents/' . $document['body']['$id'], array_merge([
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

        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['moviesId'] . '/documents', $headers, [
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

        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, $headers, [
            'data' => [
                'title' => 'Updated Date Test',
            ]
        ]);

        $updatedAtSecond = $document['body']['$updatedAt'];

        $this->assertEquals($document['body']['title'], 'Updated Date Test');
        $this->assertEquals($document['body']['$createdAt'], $createdAt);
        $this->assertNotEquals($document['body']['$updatedAt'], $updatedAt);

        \sleep(1);

        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['moviesId'] . '/documents/' . $documentId, $headers, [
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
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $movies = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        sleep(2);

        // add document
        $document = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $moviesId . '/documents', array_merge([
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
        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $moviesId . '/documents/' . $id, array_merge([
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
        $document = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $moviesId . '/documents/' . $id, array_merge([
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
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $moviesId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return [];
    }


    /**
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     */
    public function testOrQueries(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
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
        $presidents = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents', array_merge([
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

        $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents', array_merge([
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

        $document3 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents', array_merge([
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
            '/documentsdb/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents',
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
     */
    public function testTimeout(array $data): void
    {
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $data['databaseId'] . '/collections', array_merge([
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

        for ($i = 0; $i < 10; $i++) {
            $this->client->call(Client::METHOD_POST, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-timeout' => 1,
        ], $this->getHeaders()), [
            'queries' => [
                Query::notEqual('longtext', 'appwrite')->toString(),
            ],
        ]);

        $this->assertEquals(408, $response['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'], array_merge([
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
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CounterDatabase'
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        // Create document with initial count = 5
        $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $inc = $this->client->call(Client::METHOD_PATCH, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(200, $inc['headers']['status-code']);
        $this->assertEquals(6, $inc['body']['count']);

        // Verify count = 6
        $get = $this->client->call(Client::METHOD_GET, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(6, $get['body']['count']);

        // Increment by custom value 4
        $inc2 = $this->client->call(Client::METHOD_PATCH, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 4
        ]);
        $this->assertEquals(200, $inc2['headers']['status-code']);
        $this->assertEquals(10, $inc2['body']['count']);

        $get2 = $this->client->call(Client::METHOD_GET, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(10, $get2['body']['count']);

        // Test max limit exceeded
        $err = $this->client->call(Client::METHOD_PATCH, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['max' => 8]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test attribute not found
        $notFound = $this->client->call(Client::METHOD_PATCH, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId/unknown/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(404, $notFound['headers']['status-code']);

        // Test increment with value 0
        $inc3 = $this->client->call(Client::METHOD_PATCH, "/documentsdb/$databaseId/collections/$collectionId/documents/$docId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 0
        ]);
        $this->assertEquals(400, $inc3['headers']['status-code']);
    }

    public function testDecrementAttribute(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'CounterDatabase'
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        // Create document with initial count = 10
        $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $dec = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));
        $this->assertEquals(200, $dec['headers']['status-code']);
        $this->assertEquals(9, $dec['body']['count']);

        $get = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(9, $get['body']['count']);

        // Decrement by custom value 3 (count 9 -> 6)
        $dec2 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 3
        ]);
        $this->assertEquals(200, $dec2['headers']['status-code']);
        $this->assertEquals(6, $dec2['body']['count']);

        $get2 = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(6, $get2['body']['count']);

        // Test min limit exceeded
        $err = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['min' => 7]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test min limit exceeded with custom value
        $err = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 3,
            'min' => 5,
        ]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test min limit 0
        $err = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 10,
            'min' => 0,
        ]);
        $this->assertEquals(400, $err['headers']['status-code']);

        // Test type error on non-numeric attribute
        $typeErr = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/count/decrement', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), ['value' => 'not-a-number']);
        $this->assertEquals(400, $typeErr['headers']['status-code']);

        // Test decrement with value 0
        $inc3 = $this->client->call(Client::METHOD_PATCH, "/documentsdb/$databaseId/collections/$collectionId/documents/$documentId/count/increment", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'value' => 0
        ]);
        $this->assertEquals(400, $inc3['headers']['status-code']);
    }
}
