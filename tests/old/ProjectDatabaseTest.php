<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectDatabaseTest extends BaseProjects
{
    public function testRegisterSuccess(): array
    {
        return $this->initProject(['collections.read', 'collections.write', 'documents.read', 'documents.write',]);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testCollectionCreateSuccess(array $data): array
    {
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'name' => 'Actors',
            'read' => ['*'],
            'write' => ['role:1', 'role:2'],
            'rules' => [
                [
                    'label' => 'First Name',
                    'key' => 'firstName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
                [
                    'label' => 'Last Name',
                    'key' => 'lastName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
            ],
        ]);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertEquals($actors['body']['$collection'], 0);
        $this->assertEquals($actors['body']['name'], 'Actors');
        $this->assertIsArray($actors['body']['$permissions']);
        $this->assertIsArray($actors['body']['$permissions']['read']);
        $this->assertIsArray($actors['body']['$permissions']['write']);
        $this->assertCount(1, $actors['body']['$permissions']['read']);
        $this->assertCount(2, $actors['body']['$permissions']['write']);

        $movies = $this->client->call(Client::METHOD_POST, '/database/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'name' => 'Movies',
            'read' => ['*'],
            'write' => ['role:1', 'role:2'],
            'rules' => [
                [
                    'label' => 'Name',
                    'key' => 'name',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
                [
                    'label' => 'Release Year',
                    'key' => 'releaseYear',
                    'type' => 'numeric',
                    'default' => 0,
                    'required' => false,
                    'array' => false
                ],
                [
                    'label' => 'Actors',
                    'key' => 'actors',
                    'type' => 'document',
                    'default' => [],
                    'required' => false,
                    'array' => true,
                    'list' => [$actors['body']['$uid']],
                ],
            ],
        ]);

        $this->assertEquals($movies['headers']['status-code'], 201);
        $this->assertEquals($movies['body']['$collection'], 0);
        $this->assertEquals($movies['body']['name'], 'Movies');
        $this->assertIsArray($movies['body']['$permissions']);
        $this->assertIsArray($movies['body']['$permissions']['read']);
        $this->assertIsArray($movies['body']['$permissions']['write']);
        $this->assertCount(1, $movies['body']['$permissions']['read']);
        $this->assertCount(2, $movies['body']['$permissions']['write']);

        return array_merge($data, ['moviesId' => $movies['body']['$uid'], 'actorsId' => $actors['body']['$uid']]);
    }

    /**
     * @depends testCollectionCreateSuccess
     */
    public function testDocumentCreateSuccess(array $data): array
    {
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'name' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Chris',
                        'lastName' => 'Evans',
                    ],
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Samuel',
                        'lastName' => 'Jackson',
                    ],
                ]
            ]
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'name' => 'Spider-Man: Far From Home',
                'releaseYear' => 2019,
                'actors' => [
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Tom',
                        'lastName' => 'Holland',
                    ],
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Zendaya',
                        'lastName' => 'Maree Stoermer',
                    ],
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Samuel',
                        'lastName' => 'Jackson',
                    ],
                ]
            ]
        ]);

        $document3 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'name' => 'Spider-Man: Homecoming',
                'releaseYear' => 2017,
                'actors' => [
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Tom',
                        'lastName' => 'Holland',
                    ],
                    [
                        '$collection' => $data['actorsId'],
                        '$permissions' => ['read' => [], 'write' => []],
                        'firstName' => 'Zendaya',
                        'lastName' => 'Maree Stoermer',
                    ],
                ],
            ]
        ]);

        $document4 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'releaseYear' => 2020, // Missing title, expect an 400 error
            ]
        ]);

        $this->assertEquals($document1['headers']['status-code'], 201);
        $this->assertEquals($document1['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document1['body']['name'], 'Captain America');
        $this->assertEquals($document1['body']['releaseYear'], 1944);
        $this->assertIsArray($document1['body']['$permissions']);
        $this->assertIsArray($document1['body']['$permissions']['read']);
        $this->assertIsArray($document1['body']['$permissions']['write']);
        $this->assertCount(0, $document1['body']['$permissions']['read']);
        $this->assertCount(0, $document1['body']['$permissions']['write']);
        $this->assertCount(2, $document1['body']['actors']);

        $this->assertEquals($document2['headers']['status-code'], 201);
        $this->assertEquals($document2['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document2['body']['name'], 'Spider-Man: Far From Home');
        $this->assertEquals($document2['body']['releaseYear'], 2019);
        $this->assertIsArray($document2['body']['$permissions']);
        $this->assertIsArray($document2['body']['$permissions']['read']);
        $this->assertIsArray($document2['body']['$permissions']['write']);
        $this->assertCount(0, $document2['body']['$permissions']['read']);
        $this->assertCount(0, $document2['body']['$permissions']['write']);
        $this->assertCount(3, $document2['body']['actors']);
        $this->assertEquals($document2['body']['actors'][0]['firstName'], 'Tom');
        $this->assertEquals($document2['body']['actors'][0]['lastName'], 'Holland');
        $this->assertEquals($document2['body']['actors'][1]['firstName'], 'Zendaya');
        $this->assertEquals($document2['body']['actors'][1]['lastName'], 'Maree Stoermer');
        $this->assertEquals($document2['body']['actors'][2]['firstName'], 'Samuel');
        $this->assertEquals($document2['body']['actors'][2]['lastName'], 'Jackson');

        $this->assertEquals($document3['headers']['status-code'], 201);
        $this->assertEquals($document3['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document3['body']['name'], 'Spider-Man: Homecoming');
        $this->assertEquals($document3['body']['releaseYear'], 2017);
        $this->assertIsArray($document3['body']['$permissions']);
        $this->assertIsArray($document3['body']['$permissions']['read']);
        $this->assertIsArray($document3['body']['$permissions']['write']);
        $this->assertCount(0, $document3['body']['$permissions']['read']);
        $this->assertCount(0, $document3['body']['$permissions']['write']);
        $this->assertCount(2, $document3['body']['actors']);
        $this->assertEquals($document3['body']['actors'][0]['firstName'], 'Tom');
        $this->assertEquals($document3['body']['actors'][0]['lastName'], 'Holland');
        $this->assertEquals($document3['body']['actors'][1]['firstName'], 'Zendaya');
        $this->assertEquals($document3['body']['actors'][1]['lastName'], 'Maree Stoermer');

        $this->assertEquals($document4['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsListSuccessOrderAndCasting(array $data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'order-field' => 'releaseYear',
            'order-type' => 'ASC',
            'order-cast' => 'int',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'order-field' => 'releaseYear',
            'order-type' => 'DESC',
            'order-cast' => 'int',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);
    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsListSuccessLimitAndOffset(array $data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'limit' => 1,
            'order-field' => 'releaseYear',
            'order-type' => 'ASC',
            'order-cast' => 'int',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'limit' => 2,
            'offset' => 1,
            'order-field' => 'releaseYear',
            'order-type' => 'ASC',
            'order-cast' => 'int',
        ]);

        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);
    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsListSuccessFirstAndLast(array $data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'limit' => 1,
            'order-field' => 'releaseYear',
            'order-type' => 'ASC',
            'order-cast' => 'int',
            'first' => true,
        ]);

        $this->assertEquals(1944, $documents['body']['releaseYear']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'limit' => 2,
            'offset' => 1,
            'order-field' => 'releaseYear',
            'order-type' => 'ASC',
            'order-cast' => 'int',
            'last' => true,
        ]);

        $this->assertEquals(2019, $documents['body']['releaseYear']);
    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsListSuccessSearch(array $data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'search' => 'Captain America',
        ]);

        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'search' => 'Homecoming',
        ]);

        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'search' => 'spider',
        ]);

        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);
    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsListSuccessFilters(array $data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'filters' => [
                'actors.firstName=Tom'
            ],
        ]);

        $this->assertCount(2, $documents['body']['documents']);
        $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['name']);
        $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['name']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'filters' => [
                'releaseYear=1944'
            ],
        ]);

        $this->assertCount(1, $documents['body']['documents']);
        $this->assertEquals('Captain America', $documents['body']['documents'][0]['name']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'filters' => [
                'releaseYear!=1944'
            ],
        ]);

        $this->assertCount(2, $documents['body']['documents']);
        $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['name']);
        $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['name']);
    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsUpdateSuccess(array $data): void
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'name' => 'Thor: Ragnaroc',
                'releaseYear' => 2017,
            ]
        ]);

        $id = $document['body']['$uid'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['name'], 'Thor: Ragnaroc');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $collection . '/documents/' . $id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'name' => 'Thor: Ragnarok'
            ]
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['name'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ]);

        $id = $document['body']['$uid'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['name'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

    }

    /**
     * @depends testDocumentCreateSuccess
     */
    public function testDocumentsDeleteSuccess(array $data): void
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'data' => [
                'name' => 'Thor: Ragnarok',
                'releaseYear' => 2017,
            ]
        ]);

        $id = $document['body']['$uid'];
        $collection = $document['body']['$collection'];

        $this->assertEquals($document['headers']['status-code'], 201);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);

        $document = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $collection . '/documents/' . $id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 204);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collection . '/documents/' . $id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 404);

    }
}
