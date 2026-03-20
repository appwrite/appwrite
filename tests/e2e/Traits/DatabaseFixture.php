<?php

namespace Tests\E2E\Traits;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

/**
 * Provides pre-created database fixtures for read-only tests.
 * Fixtures are created once per test class and reused across test methods.
 */
trait DatabaseFixture
{
    protected static ?string $fixtureDatabaseId = null;
    protected static ?string $fixtureMoviesId = null;
    protected static ?string $fixtureActorsId = null;
    protected static array $fixtureDocumentIds = [];
    protected static bool $fixturesInitialized = false;

    protected function getFixtureDatabaseId(): string
    {
        $this->ensureFixturesCreated();
        return self::$fixtureDatabaseId;
    }

    protected function getFixtureMoviesId(): string
    {
        $this->ensureFixturesCreated();
        return self::$fixtureMoviesId;
    }

    protected function getFixtureActorsId(): string
    {
        $this->ensureFixturesCreated();
        return self::$fixtureActorsId;
    }

    protected function getFixtureDocumentIds(): array
    {
        $this->ensureFixturesCreated();
        return self::$fixtureDocumentIds;
    }

    protected function ensureFixturesCreated(): void
    {
        if (self::$fixturesInitialized) {
            return;
        }

        $this->createDatabaseFixtures();
        self::$fixturesInitialized = true;
    }

    protected function createDatabaseFixtures(): void
    {
        $config = $this->getSchemaApiConfig();
        $isTablesDB = $config['basePath'] === '/tablesdb';

        // Create database
        $database = $this->client->call(Client::METHOD_POST, $config['basePath'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Fixture Database'
        ]);

        self::$fixtureDatabaseId = $database['body']['$id'];
        $databaseId = self::$fixtureDatabaseId;

        $collectionEndpoint = $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'];
        $collectionKey = $isTablesDB ? 'tableId' : 'collectionId';
        $docKey = $isTablesDB ? 'rowId' : 'documentId';
        $docEndpoint = $isTablesDB ? 'rows' : 'documents';

        // Create Movies collection
        $movies = $this->client->call(Client::METHOD_POST, $collectionEndpoint, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            $collectionKey => ID::unique(),
            'name' => 'Movies',
            ($isTablesDB ? 'rowSecurity' : 'documentSecurity') => true,
            'permissions' => [
                Permission::create(Role::users()),
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        self::$fixtureMoviesId = $movies['body']['$id'];

        // Create Actors collection
        $actors = $this->client->call(Client::METHOD_POST, $collectionEndpoint, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            $collectionKey => ID::unique(),
            'name' => 'Actors',
            ($isTablesDB ? 'rowSecurity' : 'documentSecurity') => true,
            'permissions' => [
                Permission::create(Role::users()),
                Permission::read(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);

        self::$fixtureActorsId = $actors['body']['$id'];

        // Create attributes on Movies
        $attrEndpoint = $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . self::$fixtureMoviesId . '/' . $config['attributePath'];

        $this->client->call(Client::METHOD_POST, $attrEndpoint . '/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, $attrEndpoint . '/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'description',
            'size' => 512,
            'required' => false,
            'default' => '',
        ]);

        $this->client->call(Client::METHOD_POST, $attrEndpoint . '/integer', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'releaseYear',
            'required' => false,
            'default' => 0,
        ]);

        $this->client->call(Client::METHOD_POST, $attrEndpoint . '/float', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'rating',
            'required' => false,
            'default' => 0.0,
        ]);

        $this->client->call(Client::METHOD_POST, $attrEndpoint . '/boolean', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'active',
            'required' => false,
            'default' => true,
        ]);

        // Create attributes on Actors
        $actorAttrEndpoint = $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . self::$fixtureActorsId . '/' . $config['attributePath'];

        $this->client->call(Client::METHOD_POST, $actorAttrEndpoint . '/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->waitForAllAttributes($databaseId, self::$fixtureMoviesId);
        $this->waitForAllAttributes($databaseId, self::$fixtureActorsId);

        // Create indexes
        $indexEndpoint = $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . self::$fixtureMoviesId . '/' . $config['indexPath'];

        $this->client->call(Client::METHOD_POST, $indexEndpoint, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title_index',
            'type' => 'key',
            'attributes' => ['title'],
        ]);

        $this->waitForAllIndexes($databaseId, self::$fixtureMoviesId);

        // Create sample documents
        $docsEndpoint = $config['basePath'] . '/' . $databaseId . '/' . $config['collectionPath'] . '/' . self::$fixtureMoviesId . '/' . $docEndpoint;

        $sampleMovies = [
            ['title' => 'Inception', 'description' => 'A mind-bending thriller', 'releaseYear' => 2010, 'rating' => 8.8, 'active' => true],
            ['title' => 'The Matrix', 'description' => 'A sci-fi classic', 'releaseYear' => 1999, 'rating' => 8.7, 'active' => true],
            ['title' => 'Interstellar', 'description' => 'Space exploration epic', 'releaseYear' => 2014, 'rating' => 8.6, 'active' => true],
        ];

        foreach ($sampleMovies as $movie) {
            $doc = $this->client->call(Client::METHOD_POST, $docsEndpoint, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                $docKey => ID::unique(),
                'data' => $movie,
                'permissions' => [
                    Permission::read(Role::users()),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ],
            ]);

            self::$fixtureDocumentIds[] = $doc['body']['$id'];
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixtureDatabaseId = null;
        self::$fixtureMoviesId = null;
        self::$fixtureActorsId = null;
        self::$fixtureDocumentIds = [];
        self::$fixturesInitialized = false;

        parent::tearDownAfterClass();
    }
}
