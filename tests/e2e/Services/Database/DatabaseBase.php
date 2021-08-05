<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Client;

trait DatabaseBase
{
    public function testCreateCollection():array
    {
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($movies['headers']['status-code'], 201);
        $this->assertEquals($movies['body']['name'], 'Movies');

        return ['moviesId' => $movies['body']['$id']];
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateAttributes(array $data): array
    {
        $title = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $releaseYear = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'releaseYear',
            'size' => 0,
            'required' => true,
        ]);

        $actors = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'actors',
            'size' => 256,
            'required' => false,
            'default' => null,
            'array' => true,
        ]);

        $this->assertEquals($title['headers']['status-code'], 201);
        $this->assertEquals($title['body']['$collection'], $data['moviesId']);
        $this->assertEquals($title['body']['$id'], 'title');
        $this->assertEquals($title['body']['type'], 'string');
        $this->assertEquals($title['body']['size'], 256);
        $this->assertEquals($title['body']['required'], true);

        $this->assertEquals($releaseYear['headers']['status-code'], 201);
        $this->assertEquals($releaseYear['body']['$collection'], $data['moviesId']);
        $this->assertEquals($releaseYear['body']['$id'], 'releaseYear');
        $this->assertEquals($releaseYear['body']['type'], 'integer');
        $this->assertEquals($releaseYear['body']['size'], 0);
        $this->assertEquals($releaseYear['body']['required'], true);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertEquals($actors['body']['$collection'], $data['moviesId']);
        $this->assertEquals($actors['body']['$id'], 'actors');
        $this->assertEquals($actors['body']['type'], 'string');
        $this->assertEquals($actors['body']['size'], 256);
        $this->assertEquals($actors['body']['required'], false);
        $this->assertEquals($actors['body']['array'], true);

        // wait for database worker to create attributes
        sleep(10);

        $movies = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []); 

        $this->assertEquals($movies['body']['$id'], $title['body']['$collection']);
        $this->assertEquals($movies['body']['$id'], $releaseYear['body']['$collection']);
        $this->assertEquals($movies['body']['$id'], $actors['body']['$collection']);
        $this->assertIsArray($movies['body']['attributesInQueue']);
        $this->assertCount(0, $movies['body']['attributesInQueue']);
        $this->assertIsArray($movies['body']['attributes']);
        $this->assertCount(3, $movies['body']['attributes']);
        $this->assertEquals($movies['body']['attributes'][0]['$id'], $title['body']['$id']);
        $this->assertEquals($movies['body']['attributes'][1]['$id'], $releaseYear['body']['$id']);
        $this->assertEquals($movies['body']['attributes'][2]['$id'], $actors['body']['$id']);

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testCreateIndexes(array $data): array
    {
        $titleIndex = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'titleIndex',
            'type' => 'fulltext',
            'attributes' => ['title'],
        ]);

        $this->assertEquals($titleIndex['headers']['status-code'], 201);
        $this->assertEquals($titleIndex['body']['$collection'], $data['moviesId']);
        $this->assertEquals($titleIndex['body']['$id'], 'titleIndex');
        $this->assertEquals($titleIndex['body']['type'], 'fulltext');
        $this->assertCount(1, $titleIndex['body']['attributes']);
        $this->assertEquals($titleIndex['body']['attributes'][0], 'title');

        // wait for database worker to create index
        sleep(5);

        $movies = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []); 

        $this->assertEquals($movies['body']['$id'], $titleIndex['body']['$collection']);
        $this->assertIsArray($movies['body']['indexes']);
        $this->assertCount(1, $movies['body']['indexes']);
        $this->assertEquals($movies['body']['indexes'][0]['$id'], $titleIndex['body']['$id']);

        return $data;
    }

    /**
     * @depends testCreateIndexes
     */
    public function testCreateDocument(array $data):array
    {
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Spider-Man: Far From Home',
                'releaseYear' => 2019,
                'actors' => [
                    'Tom Holland',
                    'Zendaya Maree Stoermer',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $document3 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Spider-Man: Homecoming',
                'releaseYear' => 2017,
                'actors' => [
                    'Tom Holland',
                    'Zendaya Maree Stoermer',
                ],
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $document4 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'releaseYear' => 2020, // Missing title, expect an 400 error
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $this->assertEquals($document1['headers']['status-code'], 201);
        $this->assertEquals($document1['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document1['body']['title'], 'Captain America');
        $this->assertEquals($document1['body']['releaseYear'], 1944);
        $this->assertIsArray($document1['body']['$read']);
        $this->assertIsArray($document1['body']['$write']);
        $this->assertCount(1, $document1['body']['$read']);
        $this->assertCount(1, $document1['body']['$write']);
        $this->assertCount(2, $document1['body']['actors']);
        $this->assertEquals($document1['body']['actors'][0], 'Chris Evans');
        $this->assertEquals($document1['body']['actors'][1], 'Samuel Jackson');

        $this->assertEquals($document2['headers']['status-code'], 201);
        $this->assertEquals($document2['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document2['body']['title'], 'Spider-Man: Far From Home');
        $this->assertEquals($document2['body']['releaseYear'], 2019);
        $this->assertIsArray($document2['body']['$read']);
        $this->assertIsArray($document2['body']['$write']);
        $this->assertCount(1, $document2['body']['$read']);
        $this->assertCount(1, $document2['body']['$write']);
        $this->assertCount(3, $document2['body']['actors']);
        $this->assertEquals($document2['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($document2['body']['actors'][1], 'Zendaya Maree Stoermer');
        $this->assertEquals($document2['body']['actors'][2], 'Samuel Jackson');

        $this->assertEquals($document3['headers']['status-code'], 201);
        $this->assertEquals($document3['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document3['body']['title'], 'Spider-Man: Homecoming');
        $this->assertEquals($document3['body']['releaseYear'], 2017);
        $this->assertIsArray($document3['body']['$read']);
        $this->assertIsArray($document3['body']['$write']);
        $this->assertCount(1, $document3['body']['$read']);
        $this->assertCount(1, $document3['body']['$write']);
        $this->assertCount(2, $document3['body']['actors']);
        $this->assertEquals($document2['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($document2['body']['actors'][1], 'Zendaya Maree Stoermer');

        $this->assertEquals($document4['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocuments(array $data):array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['DESC'],
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsLimitAndOffset(array $data):array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 2,
            'offset' => 1,
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testDocumentsListSuccessSearch(array $data):array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['title.search("Captain America")'],
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['title.search("Homecoming")'],
        ]);

        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['title.search("spider")'],
        ]);

        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        return [];
    }
    // TODO@kodumbeats test for empty searches and misformatted queries

    /**
     * @depends testCreateDocument
     */
    // public function testListDocumentsFilters(array $data):array
    // {
    //     $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'filters' => [
    //             'actors.firstName=Tom'
    //         ],
    //     ]);

    //     $this->assertCount(2, $documents['body']['documents']);
    //     $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['name']);
    //     $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['name']);

    //     $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'filters' => [
    //             'releaseYear=1944'
    //         ],
    //     ]);

    //     $this->assertCount(1, $documents['body']['documents']);
    //     $this->assertEquals('Captain America', $documents['body']['documents'][0]['name']);

    //     $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'filters' => [
    //             'releaseYear!=1944'
    //         ],
    //     ]);

    //     $this->assertCount(2, $documents['body']['documents']);
    //     $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['name']);
    //     $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['name']);

    //     return [];
    // }

    /**
     * @depends testCreateDocument
     */
    public function testUpdateDocument(array $data):array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Thor: Ragnaroc',
                'releaseYear' => 2017,
                'actors' => [],
            ],
            'read' => ['user:'.$this->getUser()['$id'], 'user:testx'],
            'write' => ['user:'.$this->getUser()['$id'], 'user:testy'],
        ]);

        $id = $document['body']['$id'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnaroc');
        $this->assertEquals($document['body']['releaseYear'], 2017);
        $this->assertEquals($document['body']['$read'][1], 'user:testx');
        $this->assertEquals($document['body']['$write'][1], 'user:testy');

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $id = $document['body']['$id'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testDeleteDocument(array $data):array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2017,
                'actors' => [],
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $id = $document['body']['$id'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 201);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 200);

        $document = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 204);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 404);
        
        return $data;
    }

    public function testInvalidDocumentStructure()
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'invalidDocumentStructure',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals('invalidDocumentStructure', $collection['body']['name']);

        $collectionId = $collection['body']['$id'];

        $email = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'email',
            'required' => false,
        ]);

        $ip = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'ip',
            'required' => false,
        ]);

        $url = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'url',
            'size' => 256,
            'required' => false,
        ]);

        $range = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'range',
            'required' => false,
            'min' => 1,
            'max' => 10,
        ]);

        // TODO@kodumbeats min and max are rounded in error message
        $floatRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'floatRange',
            'required' => false,
            'min' => 1.1,
            'max' => 1.4,
        ]);

        // TODO@kodumbeats float validator rejects 0.0 and 1.0 as floats
        // $probability = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/float', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        //     'x-appwrite-key' => $this->getProject()['apiKey']
        // ]), [
        //     'attributeId' => 'probability',
        //     'required' => false,
        //     'min' => \floatval(0.0),
        //     'max' => \floatval(1.0),
        // ]);

        $upperBound = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'upperBound',
            'required' => false,
            'max' => 10,
        ]);

        $lowerBound = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'lowerBound',
            'required' => false,
            'min' => 5,
        ]);

        /**
         * Test for failure
         */

        // TODO@kodumbeats troubleshoot
        // $invalidRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
        //     'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
        //     'x-appwrite-key' => $this->getProject()['apiKey']
        // ]), [
        //     'attributeId' => 'invalidRange',
        //     'required' => false,
        //     'min' => 4,
        //     'max' => 3,
        // ]);

        $this->assertEquals(201, $email['headers']['status-code']);
        $this->assertEquals(201, $ip['headers']['status-code']);
        $this->assertEquals(201, $url['headers']['status-code']);
        $this->assertEquals(201, $range['headers']['status-code']);
        $this->assertEquals(201, $floatRange['headers']['status-code']);
        $this->assertEquals(201, $upperBound['headers']['status-code']);
        $this->assertEquals(201, $lowerBound['headers']['status-code']);
        // $this->assertEquals(400, $invalidRange['headers']['status-code']);
        // $this->assertEquals('Minimum value must be lesser than maximum value', $invalidRange['body']['message']);

        // wait for worker to add attributes
        sleep(10);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), []); 

        $this->assertCount(7, $collection['body']['attributes']);
        $this->assertCount(0, $collection['body']['attributesInQueue']);

        /**
         * Test for successful validation
         */

        $goodEmail = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'email' => 'user@example.com',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $goodIp = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'ip' => '1.1.1.1',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $goodUrl = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'url' => 'http://www.example.com',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $goodRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'range' => 3,
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $goodFloatRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'floatRange' => 1.4, 
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $notTooHigh = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'upperBound' => 8, 
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $notTooLow = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'lowerBound' => 8, 
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $this->assertEquals(201, $goodEmail['headers']['status-code']);
        $this->assertEquals(201, $goodIp['headers']['status-code']);
        $this->assertEquals(201, $goodUrl['headers']['status-code']);
        $this->assertEquals(201, $goodRange['headers']['status-code']);
        $this->assertEquals(201, $goodFloatRange['headers']['status-code']);
        $this->assertEquals(201, $notTooHigh['headers']['status-code']);
        $this->assertEquals(201, $notTooLow['headers']['status-code']);

        /*
         * Test that custom validators reject documents
         */

        $badEmail = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'email' => 'user@@example.com',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $badIp = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'ip' => '1.1.1.1.1',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $badUrl = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'url' => 'example...com',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $badRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'range' => 11,
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $badFloatRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'floatRange' => 2.5,
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $tooHigh = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'upperBound' => 11,
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $tooLow = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'lowerBound' => 3,
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);


        $this->assertEquals(400, $badEmail['headers']['status-code']);
        $this->assertEquals(400, $badIp['headers']['status-code']);
        $this->assertEquals(400, $badUrl['headers']['status-code']);
        $this->assertEquals(400, $badRange['headers']['status-code']);
        $this->assertEquals(400, $badFloatRange['headers']['status-code']);
        $this->assertEquals(400, $tooHigh['headers']['status-code']);
        $this->assertEquals(400, $tooLow['headers']['status-code']);
        $this->assertEquals('Invalid document structure: Attribute "email" has invalid format. Value must be a valid email address', $badEmail['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "ip" has invalid format. Value must be a valid IP address', $badIp['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "url" has invalid format. Value must be a valid URL', $badUrl['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "range" has invalid format. Value must be a valid range between 1 and 10', $badRange['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "floatRange" has invalid format. Value must be a valid range between 1 and 1', $badFloatRange['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "upperBound" has invalid format. Value must be a valid range between inf and 10', $tooHigh['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "lowerBound" has invalid format. Value must be a valid range between 5 and inf', $tooLow['body']['message']);
    }

    /**
     * @depends testDeleteDocument
     */
    public function testDefaultPermissions(array $data):array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [],
            ],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document['body']['title'], 'Captain America');
        $this->assertEquals($document['body']['releaseYear'], 1944);
        $this->assertIsArray($document['body']['$read']);
        $this->assertIsArray($document['body']['$write']);

        if($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(1, $document['body']['$write']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$read']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$write']);    
        }

        if($this->getSide() == 'server') {
            $this->assertCount(0, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals([], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);    
        }

        // Updated and Inherit Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Captain America 2',
                'releaseYear' => 1945,
                'actors' => [],
            ],
            'read' => ['role:all'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        if($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(1, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$write']);    
        }

        if($this->getSide() == 'server') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);    
        }

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        if($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(1, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$write']);    
        }

        if($this->getSide() == 'server') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);    
        }

        // Reset Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Captain America 3',
                'releaseYear' => 1946,
                'actors' => [],
            ],
            'read' => [],
            'write' => [],
        ]);

        if($this->getSide() == 'client') {
            $this->assertEquals($document['headers']['status-code'], 401);
        }

        if($this->getSide() == 'server') {
            $this->assertEquals($document['headers']['status-code'], 200);
            $this->assertEquals($document['body']['title'], 'Captain America 3');
            $this->assertEquals($document['body']['releaseYear'], 1946);
            $this->assertCount(0, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals([], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);    
        }

        return $data;
    }
}