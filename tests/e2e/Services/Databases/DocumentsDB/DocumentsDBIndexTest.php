<?php

namespace Tests\E2E\Services\Databases\DocumentsDB;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;

class DocumentsDBIndexTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testCreateIndexes(): void
    {
        $database = $this->client->call(
            'POST',
            '/documentsdb',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'databaseId' => ID::unique(),
                'name' => 'DocumentsDB Indexes',
            ]
        );

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $movies = $this->client->call(
            'POST',
            '/documentsdb/' . $databaseId . '/collections',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'collectionId' => ID::unique(),
                'name' => 'Movies',
                'documentSecurity' => true,
            ]
        );

        $this->assertEquals(201, $movies['headers']['status-code']);
        $moviesId = $movies['body']['$id'];

        $titleIndex = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'titleIndex',
            'type' => 'fulltext',
            'attributes' => ['title'],
        ]);

        $this->assertEquals(202, $titleIndex['headers']['status-code']);
        $this->assertEquals('titleIndex', $titleIndex['body']['key']);
        $this->assertEquals('fulltext', $titleIndex['body']['type']);
        $this->assertCount(1, $titleIndex['body']['attributes']);
        $this->assertEquals('title', $titleIndex['body']['attributes'][0]);

        $releaseYearIndex = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'releaseYear',
            'type' => 'key',
            'attributes' => ['releaseYear'],
        ]);

        $this->assertEquals(202, $releaseYearIndex['headers']['status-code']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['key']);
        $this->assertEquals('key', $releaseYearIndex['body']['type']);
        $this->assertCount(1, $releaseYearIndex['body']['attributes']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['attributes'][0]);

        $releaseWithDate1 = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
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

        $releaseWithDate2 = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'birthDay',
            'type' => 'key',
            'attributes' => ['birthDay'],
        ]);

        $this->assertEquals(202, $releaseWithDate2['headers']['status-code']);
        $this->assertEquals('birthDay', $releaseWithDate2['body']['key']);
        $this->assertEquals('key', $releaseWithDate2['body']['type']);
        $this->assertCount(1, $releaseWithDate2['body']['attributes']);
        $this->assertEquals('birthDay', $releaseWithDate2['body']['attributes'][0]);

        // Failure cases
        $fulltextReleaseYear = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'releaseYearDated',
            'type' => 'fulltext',
            'attributes' => ['releaseYear'],
        ]);
        $this->assertEquals(400, $fulltextReleaseYear['headers']['status-code']);

        $noAttributes = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'none',
            'type' => 'key',
            'attributes' => [],
        ]);
        $this->assertEquals(400, $noAttributes['headers']['status-code']);

        $duplicates = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'duplicate',
            'type' => 'fulltext',
            'attributes' => ['releaseYear', 'releaseYear'],
        ]);
        $this->assertEquals(400, $duplicates['headers']['status-code']);

        $tooLong = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'tooLong',
            'type' => 'key',
            'attributes' => ['description', 'tagline'],
        ]);
        $this->assertEquals(202, $tooLong['headers']['status-code']);

        $fulltextArray = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'ft',
            'type' => 'fulltext',
            'attributes' => ['actors'],
        ]);
        $this->assertEquals(400, $fulltextArray['headers']['status-code']);

        $actorsArray = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'index-actors',
            'type' => 'key',
            'attributes' => ['actors'],
        ]);
        $this->assertEquals(202, $actorsArray['headers']['status-code']);

        $twoLevelsArray = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'index-ip-actors',
            'type' => 'key',
            'attributes' => ['releaseYear', 'actors'],
            'orders' => ['DESC', 'DESC'],
        ]);
        $this->assertEquals(202, $twoLevelsArray['headers']['status-code']);

        $unknown = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'index-unknown',
            'type' => 'key',
            'attributes' => ['Unknown'],
        ]);
        $this->assertEquals(202, $unknown['headers']['status-code']);

        $index1 = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'integers-order',
            'type' => 'key',
            'attributes' => ['integers'],
            'orders' => ['DESC'],
        ]);
        $this->assertEquals(202, $index1['headers']['status-code']);

        $index2 = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$moviesId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'integers-size',
            'type' => 'key',
            'attributes' => ['integers'],
        ]);
        $this->assertEquals(202, $index2['headers']['status-code']);

        // Let worker create indexes
        sleep(2);

        $moviesWithIndexes = $this->client->call('GET', "/documentsdb/{$databaseId}/collections/{$moviesId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertIsArray($moviesWithIndexes['body']['indexes']);
        $this->assertCount(10, $moviesWithIndexes['body']['indexes']);

        $this->assertEventually(function () use ($databaseId, $moviesId) {
            $movies = $this->client->call('GET', "/documentsdb/{$databaseId}/collections/{$moviesId}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            foreach ($movies['body']['indexes'] as $index) {
                $this->assertEquals('available', $index['status']);
            }

            return true;
        }, 60000, 500);
    }

    public function testGetIndexByKeyWithLengths(): void
    {
        $database = $this->client->call(
            'POST',
            '/documentsdb',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'databaseId' => ID::unique(),
                'name' => 'DocumentsDB Index Lengths',
            ]
        );

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(
            'POST',
            "/documentsdb/{$databaseId}/collections",
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'collectionId' => ID::unique(),
                'name' => 'Movies',
                'documentSecurity' => true,
            ]
        );

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        $create = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'lengthTestIndex',
            'type' => 'key',
            'attributes' => ['title', 'description'],
            'lengths' => [128, 200],
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        $index = $this->client->call('GET', "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes/lengthTestIndex", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals(200, $index['headers']['status-code']);
        $this->assertEquals('lengthTestIndex', $index['body']['key']);
        $this->assertEquals([128, 200], $index['body']['lengths']);

        $create = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'lengthOverrideTestIndex',
            'type' => 'key',
            'attributes' => ['actors-new'],
            'lengths' => [Database::MAX_ARRAY_INDEX_LENGTH],
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        $index = $this->client->call('GET', "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes/lengthOverrideTestIndex", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertEquals([Database::MAX_ARRAY_INDEX_LENGTH], $index['body']['lengths']);

        $create = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'lengthCountExceededIndex',
            'type' => 'key',
            'attributes' => ['title-not-throw-error'],
            'lengths' => [128, 128],
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        $create = $this->client->call('POST', "/documentsdb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'lengthTooLargeIndex',
            'type' => 'key',
            'attributes' => ['title', 'description', 'tagline', 'actors'],
            'lengths' => [256, 256, 256, 20],
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);
    }
}
