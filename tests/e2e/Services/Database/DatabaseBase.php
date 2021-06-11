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
            'id' => 'Movies',
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
        $title = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'title',
            'type' => 'string',
            'size' => 256,
            'required' => true,
        ]);

        $releaseYear = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'releaseYear',
            'type' => 'integer',
            'size' => 0,
            'required' => true,
        ]);

        $actors = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'actors',
            'type' => 'string',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $this->assertEquals(1,1);

        return $data;
    }

    // TODO@kodumbeats create and test indexes

    /**
     * @depends testCreateAttributes
     */
    public function testCreateDocument(array $data):array
    {
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
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
            'orderField' => 'releaseYear',
            'orderType' => 'ASC',
            'orderCast' => 'int',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderField' => 'releaseYear',
            'orderType' => 'DESC',
            'orderCast' => 'int',
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
            'orderField' => 'releaseYear',
            'orderType' => 'ASC',
            'orderCast' => 'int',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 2,
            'offset' => 1,
            'orderField' => 'releaseYear',
            'orderType' => 'ASC',
            'orderCast' => 'int',
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
            'search' => 'Captain America',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Homecoming',
        ]);

        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'spider',
        ]);

        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsFilters(array $data):array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'filters' => [
                'actors.firstName=Tom'
            ],
        ]);

        $this->assertCount(2, $documents['body']['documents']);
        $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['name']);
        $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['name']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'filters' => [
                'releaseYear=1944'
            ],
        ]);

        $this->assertCount(1, $documents['body']['documents']);
        $this->assertEquals('Captain America', $documents['body']['documents'][0]['name']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'filters' => [
                'releaseYear!=1944'
            ],
        ]);

        $this->assertCount(2, $documents['body']['documents']);
        $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['name']);
        $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['name']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testUpdateDocument(array $data):array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Thor: Ragnaroc',
                'releaseYear' => 2017,
            ],
            'read' => ['user:'.$this->getUser()['$id'], 'testx'],
            'write' => ['user:'.$this->getUser()['$id'], 'testy'],
        ]);

        $id = $document['body']['$id'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['name'], 'Thor: Ragnaroc');
        $this->assertEquals($document['body']['releaseYear'], 2017);
        $this->assertEquals($document['body']['$permissions']['read'][1], 'testx');
        $this->assertEquals($document['body']['$permissions']['write'][1], 'testy');

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Thor: Ragnarok'
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['name'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $id = $document['body']['$id'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['name'], 'Thor: Ragnarok');
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
            'data' => [
                'name' => 'Thor: Ragnarok',
                'releaseYear' => 2017,
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

    /**
     * @depends testDeleteDocument
     */
    public function testDefaultPermissions(array $data):array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [],
            ],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document['body']['name'], 'Captain America');
        $this->assertEquals($document['body']['releaseYear'], 1944);
        $this->assertIsArray($document['body']['$permissions']);
        $this->assertIsArray($document['body']['$permissions']['read']);
        $this->assertIsArray($document['body']['$permissions']['write']);

        if($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$permissions']['read']);
            $this->assertCount(1, $document['body']['$permissions']['write']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$permissions']['read']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$permissions']['write']);    
        }

        if($this->getSide() == 'server') {
            $this->assertCount(0, $document['body']['$permissions']['read']);
            $this->assertCount(0, $document['body']['$permissions']['write']);
            $this->assertEquals([], $document['body']['$permissions']['read']);
            $this->assertEquals([], $document['body']['$permissions']['write']);    
        }

        // Updated and Inherit Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Captain America 2',
                'releaseYear' => 1945,
                'actors' => [],
            ],
            'read' => ['role:all'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['name'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        if($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$permissions']['read']);
            $this->assertCount(1, $document['body']['$permissions']['write']);
            $this->assertEquals(['role:all'], $document['body']['$permissions']['read']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$permissions']['write']);    
        }

        if($this->getSide() == 'server') {
            $this->assertCount(1, $document['body']['$permissions']['read']);
            $this->assertCount(0, $document['body']['$permissions']['write']);
            $this->assertEquals(['role:all'], $document['body']['$permissions']['read']);
            $this->assertEquals([], $document['body']['$permissions']['write']);    
        }

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['name'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        if($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$permissions']['read']);
            $this->assertCount(1, $document['body']['$permissions']['write']);
            $this->assertEquals(['role:all'], $document['body']['$permissions']['read']);
            $this->assertEquals(['user:'.$this->getUser()['$id']], $document['body']['$permissions']['write']);    
        }

        if($this->getSide() == 'server') {
            $this->assertCount(1, $document['body']['$permissions']['read']);
            $this->assertCount(0, $document['body']['$permissions']['write']);
            $this->assertEquals(['role:all'], $document['body']['$permissions']['read']);
            $this->assertEquals([], $document['body']['$permissions']['write']);    
        }

        // Reset Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Captain America 3',
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
            $this->assertEquals($document['body']['name'], 'Captain America 3');
            $this->assertEquals($document['body']['releaseYear'], 1946);
            $this->assertCount(0, $document['body']['$permissions']['read']);
            $this->assertCount(0, $document['body']['$permissions']['write']);
            $this->assertEquals([], $document['body']['$permissions']['read']);
            $this->assertEquals([], $document['body']['$permissions']['write']);    
        }

        return $data;
    }
}