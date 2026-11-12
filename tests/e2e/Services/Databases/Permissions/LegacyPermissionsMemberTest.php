<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SchemaPolling;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Database\Role;

final class LegacyPermissionsMemberTest extends Scope
{
    use DatabasesPermissionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiLegacy;
    use SchemaPolling;

    public array $collections = [];

    public function createUsers(): array
    {
        return [
            'user1' => $this->createUser('user1', 'lorem@ipsum.com'),
            'user2' => $this->createUser('user2', 'dolor@ipsum.com'),
        ];
    }

    public static function permissionsProvider(): \Iterator
    {
        yield [[Permission::read(Role::any())], 1, 1, 1];
        yield [[Permission::read(Role::users())], 1, 1, 1];
        yield [[Permission::read(Role::user(Id::custom('random')))], 1, 1, 0];
        yield [[Permission::read(Role::user(Id::custom('lorem'))), Permission::update(Role::user('lorem')), Permission::delete(Role::user('lorem'))], 1, 1, 0];
        yield [[Permission::read(Role::user(Id::custom('dolor'))), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))], 1, 1, 0];
        yield [[Permission::read(Role::user(Id::custom('dolor'))), Permission::read(Role::user('lorem')), Permission::update(Role::user('dolor')), Permission::delete(Role::user('dolor'))], 1, 1, 0];
        yield [[Permission::update(Role::any()), Permission::delete(Role::any())], 1, 1, 0];
        yield [[Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())], 1, 1, 1];
        yield [[Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())], 1, 1, 1];
        yield [[Permission::read(Role::user(Id::custom('user1')))], 1, 1, 1];
        yield [[Permission::read(Role::user(Id::custom('user1'))), Permission::read(Role::user(Id::custom('user1')))], 1, 1, 1];
        yield [[Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())], 1, 1, 1];
    }

    /**
     * Setup database helper with caching
     */
    protected function setupDatabase(): array
    {
        $cacheKey = $this->getProject()['$id'] . '_' . static::class;

        if (!empty(self::$setupDatabaseCache[$cacheKey])) {
            return self::$setupDatabaseCache[$cacheKey];
        }

        $this->createUsers();

        $db = $this->client->call(
            Client::METHOD_POST,
            $this->getDatabaseUrl(),
            $this->getServerHeader(),
            [
                'databaseId' => Id::unique(),
                'name' => 'Test Database',
            ]
        );
        $this->assertEquals(201, $db['headers']['status-code']);

        $databaseId = $db['body']['$id'];

        $public = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => Id::unique(),
                'name' => 'Movies',
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                $this->getSecurityParam() => true,
            ]
        );
        $this->assertEquals(201, $public['headers']['status-code']);
        $this->collections = ['public' => $public['body']['$id']];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $this->collections['public'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->assertEquals(202, $response['headers']['status-code']);

        $private = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => Id::unique(),
                'name' => 'Private Movies',
                'permissions' => [
                    Permission::read(Role::users()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
                $this->getSecurityParam() => true,
            ]
        );
        $this->assertEquals(201, $private['headers']['status-code']);
        $this->collections['private'] = $private['body']['$id'];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $this->collections['private'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->assertEquals(202, $response['headers']['status-code']);

        $doconly = $this->client->call(
            Client::METHOD_POST,
            $this->getContainerUrl($databaseId),
            $this->getServerHeader(),
            [
                $this->getContainerIdParam() => Id::unique(),
                'name' => 'Document Only Movies',
                'permissions' => [],
                $this->getSecurityParam() => true,
            ]
        );
        $this->assertEquals(201, $doconly['headers']['status-code']);
        $this->collections['doconly'] = $doconly['body']['$id'];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getSchemaUrl($databaseId, $this->collections['doconly'], 'string'),
            $this->getServerHeader(),
            [
                'key' => 'title',
                'size' => 256,
                'required' => true,
            ]
        );
        $this->assertEquals(202, $response['headers']['status-code']);

        $this->waitForAttribute($databaseId, $this->collections['public'], 'title');
        $this->waitForAttribute($databaseId, $this->collections['private'], 'title');
        $this->waitForAttribute($databaseId, $this->collections['doconly'], 'title');

        self::$setupDatabaseCache[$cacheKey] = [
            'users' => $this->users,
            'collections' => $this->collections,
            'databaseId' => $databaseId
        ];

        return self::$setupDatabaseCache[$cacheKey];
    }

    /**
     * Setup database test
     */
    public function testSetupDatabase(): void
    {
        $data = $this->setupDatabase();
        $this->assertNotEmpty($data['databaseId']);
    }

    #[DataProvider('permissionsProvider')]
    public function testReadDocuments($permissions, $anyCount, $usersCount, $docOnlyCount)
    {
        $data = $this->setupDatabase();
        $users = $data['users'];
        $collections = $data['collections'];
        $databaseId = $data['databaseId'];

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $collections['public']),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => Id::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions
            ]
        );
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $collections['private']),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => Id::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions
            ]
        );
        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(
            Client::METHOD_POST,
            $this->getRecordUrl($databaseId, $collections['doconly']),
            $this->getServerHeader(),
            [
                $this->getRecordIdParam() => Id::unique(),
                'data' => [
                    'title' => 'Lorem',
                ],
                'permissions' => $permissions
            ]
        );
        $this->assertEquals(201, $response['headers']['status-code']);

        /**
         * Check "any" permission collection
         */
        $documents = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $collections['public']),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertGreaterThanOrEqual($anyCount, $documents['body']['total']);

        /**
         * Check "users" permission collection
         */
        $documents = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $collections['private']),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertGreaterThanOrEqual($usersCount, $documents['body']['total']);

        /**
         * Check "user:user1" document only permission collection
         */
        $documents = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $collections['doconly']),
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $users['user1']['session'],
            ]
        );

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertGreaterThanOrEqual($docOnlyCount, $documents['body']['total']);
    }

    public function testNestedRelatedDocumentsFollowTheirCollectionsPermissions(): void
    {
        $data = $this->setupDatabase();
        $databaseId = $data['databaseId'];
        $user = $data['users']['user1'];
        $session = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $user['session'],
        ];

        $authors = $this->createNestedCollection($databaseId, 'Authors', [Permission::read(Role::users()), Permission::create(Role::users())], true);
        $readOnly = $this->createNestedCollection($databaseId, 'Read only books', [Permission::read(Role::users())], false);
        $ownedOnly = $this->createNestedCollection($databaseId, 'Owned books', [Permission::read(Role::users())], true);
        $writable = $this->createNestedCollection($databaseId, 'Writable books', [Permission::read(Role::users()), Permission::create(Role::users()), Permission::update(Role::users())], false);

        foreach ([$readOnly, $ownedOnly, $writable] as $key => $books) {
            $relationship = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $authors, 'relationship'), $this->getServerHeader(), [
                $this->getRelatedIdParam() => $books,
                'type' => RelationshipType::OneToMany->value,
                'key' => 'books' . $key,
                'twoWay' => true,
                'twoWayKey' => 'author',
                'onDelete' => RelationshipDeleteAction::SetNull->value,
            ]);
            $this->assertSame(202, $relationship['headers']['status-code']);
            $this->waitForAttribute($databaseId, $authors, 'books' . $key);
        }

        $stored = [];
        foreach (['readOnly' => [$readOnly, []], 'owned' => [$ownedOnly, [Permission::read(Role::user($user['$id'])), Permission::update(Role::user($user['$id']))]], 'unowned' => [$ownedOnly, []], 'writable' => [$writable, []]] as $name => [$books, $permissions]) {
            $book = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $books), $this->getServerHeader(), [
                $this->getRecordIdParam() => Id::unique(),
                'data' => ['title' => $name],
                'permissions' => $permissions,
            ]);
            $this->assertSame(201, $book['headers']['status-code']);
            $stored[$name] = $book['body']['$id'];
        }

        $create = fn (array $data, array $headers): array => $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $authors), $headers, [
            $this->getRecordIdParam() => Id::unique(),
            'data' => ['title' => 'Author', ...$data],
            'permissions' => [Permission::read(Role::users())],
        ]);

        $refused = $create(['books0' => [['title' => 'New']]], $session);
        $this->assertSame(401, $refused['headers']['status-code'], 'A new nested document needs create on its collection');
        $this->assertSame('user_unauthorized', $refused['body']['type']);
        $this->assertSame("No permissions provided for action 'create'", $refused['body']['message'], "Main reported the related collection's create check");

        $refused = $create(['books0' => [$stored['readOnly']]], $session);
        $this->assertSame(401, $refused['headers']['status-code'], 'Linking a stored document needs update on it');
        $this->assertSame('user_unauthorized', $refused['body']['type']);

        $refused = $create(['books1' => [$stored['unowned']]], $session);
        $this->assertSame(401, $refused['headers']['status-code'], 'Document security without an update permission refuses the link');
        $this->assertSame('user_unauthorized', $refused['body']['type']);

        $linked = $create(['books1' => [$stored['owned']], 'books2' => [$stored['writable'], ['title' => 'New']]], $session);
        $this->assertSame(201, $linked['headers']['status-code'], 'Document-level or collection-level update links; collection create creates');
        $this->assertSame([$stored['owned']], \array_column($linked['body']['books1'], '$id'));
        $this->assertCount(2, $linked['body']['books2']);

        $byKey = $create(['books0' => [$stored['readOnly'], ['title' => 'New']]], $this->getServerHeader());
        $this->assertSame(201, $byKey['headers']['status-code'], 'API keys are not limited by collection permissions');
        $this->assertCount(2, $byKey['body']['books0']);
    }

    /**
     * @param list<string> $permissions
     */
    private function createNestedCollection(string $databaseId, string $name, array $permissions, bool $documentSecurity): string
    {
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $this->getServerHeader(), [
            $this->getContainerIdParam() => Id::unique(),
            'name' => $name,
            'permissions' => $permissions,
            $this->getSecurityParam() => $documentSecurity,
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);

        $title = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collection['body']['$id'], 'string'), $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => false,
        ]);
        $this->assertSame(202, $title['headers']['status-code']);
        $this->waitForAttribute($databaseId, $collection['body']['$id'], 'title');

        return $collection['body']['$id'];
    }
}
