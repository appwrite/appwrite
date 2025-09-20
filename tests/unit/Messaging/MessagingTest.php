<?php

namespace Tests\Unit\Messaging;

use Appwrite\Messaging\Adapter\Realtime;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class MessagingTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testUser(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            [
                Role::user(ID::custom('123'))->toString(),
                Role::users()->toString(),
                Role::team(ID::custom('abc'))->toString(),
                Role::team(ID::custom('abc'), 'administrator')->toString(),
                Role::team(ID::custom('abc'), 'moderator')->toString(),
                Role::team(ID::custom('def'))->toString(),
                Role::team(ID::custom('def'), 'guest')->toString(),
            ],
            ['files' => 0, 'documents' => 0, 'documents.789' => 0, 'account.123' => 0]
        );

        $event = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => [
                    0 => 'account.123',
                ]
            ]
        ];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::users()->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::user(ID::custom('123'))->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'))->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'), 'administrator')->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'), 'moderator')->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('def'))->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('def'), 'guest')->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::user(ID::custom('456'))->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('def'), 'member')->toString()];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::any()->toString()];
        $event['data']['channels'] = ['documents.123'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['data']['channels'] = ['documents.789'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['project'] = '2';

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $realtime->unsubscribe(2);

        $this->assertCount(1, $realtime->connections);
        $this->assertCount(7, $realtime->subscriptions['1']);

        $realtime->unsubscribe(1);

        $this->assertEmpty($realtime->connections);
        $this->assertEmpty($realtime->subscriptions);
    }

    public function testConvertChannelsGuest(): void
    {
        $user = new Document([
            '$id' => ''
        ]);

        $channels = [
            0 => 'files',
            1 => 'documents',
            2 => 'documents.789',
            3 => 'account',
            4 => 'account.456'
        ];

        $channels = Realtime::convertChannels($channels, $user->getId());
        $this->assertCount(4, $channels);
        $this->assertArrayHasKey('files', $channels);
        $this->assertArrayHasKey('documents', $channels);
        $this->assertArrayHasKey('documents.789', $channels);
        $this->assertArrayHasKey('account', $channels);
        $this->assertArrayNotHasKey('account.456', $channels);
    }

    public function testConvertChannelsUser(): void
    {
        $user  = new Document([
            '$id' => ID::custom('123'),
            'memberships' => [
                [
                    'teamId' => ID::custom('abc'),
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    'teamId' => ID::custom('def'),
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);
        $channels = [
            0 => 'files',
            1 => 'documents',
            2 => 'documents.789',
            3 => 'account',
            4 => 'account.456'
        ];

        $channels = Realtime::convertChannels($channels, $user->getId());

        $this->assertCount(5, $channels);
        $this->assertArrayHasKey('files', $channels);
        $this->assertArrayHasKey('documents', $channels);
        $this->assertArrayHasKey('documents.789', $channels);
        $this->assertArrayHasKey('account.123', $channels);
        $this->assertArrayHasKey('account', $channels);
        $this->assertArrayNotHasKey('account.456', $channels);
    }

    public function testFromPayloadPermissions(): void
    {
        /**
         * Test Collection Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
            ]),
            database: new Document([
                '$id' => ID::custom('database'),
            ]),
            collection: new Document([
                '$id' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertNotContains(Role::team('123abc')->toString(), $result['roles']);

        /**
         * Test Document Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
            database: new Document([
                '$id' => ID::custom('database'),
            ]),
            collection: new Document([
                '$id' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
                'documentSecurity' => true,
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertContains(Role::team('123abc')->toString(), $result['roles']);
    }

    public function testFromPayloadBucketLevelPermissions(): void
    {
        /**
         * Test Bucket Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
            ]),
            bucket: new Document([
                '$id' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertNotContains(Role::team('123abc')->toString(), $result['roles']);

        /**
         * Test File Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
            bucket: new Document([
                '$id' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
                'fileSecurity' => true
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertContains(Role::team('123abc')->toString(), $result['roles']);
    }

    //To test the new parseEvent functionality (indirectly through fromPayload)
    //This test verifies that the TODO fix for magic index accesses works correctly
    public function testParseEventFunctionality(): void
    {
        // Test users event parsing
        $result = Realtime::fromPayload(
            // switch(eventdata->service)
            event: 'users.123.create',
            payload: new Document(['$id' => 'user123'])
        );
        // check if it matches (users channel)
        $this->assertContains('account', $result['channels']);
        $this->assertContains('account.123', $result['channels']);
        $this->assertContains(Role::user(ID::custom('123'))->toString(), $result['roles']);

        // Test teams.memberships event parsing 
        $mockProject = new Document(['$id' => 'test-project', 'teamId' => 'team123']);
        $result = Realtime::fromPayload(
            event: 'teams.abc.memberships.def.update',
            payload: new Document(['$id' => 'membership123']),
            project: $mockProject
        );
        
        // check if it matches
        $this->assertContains('memberships', $result['channels']);
        $this->assertContains('memberships.def', $result['channels']);
        // For memberships, permissionsChanged is set to eventData->subAction 
        $this->assertEquals('update', $result['permissionsChanged']);

        // Test database documents event parsing
        $mockDatabase = new Document(['$id' => 'db123']);
        $mockCollection = new Document([
            '$id' => 'collection123',
            'documentSecurity' => false,
            '$read' => [Role::any()->toString()]
        ]);
        $mockPayload = new Document([
            '$id' => 'doc123',
            '$collectionId' => 'collection123'
        ]);
        
        $result = Realtime::fromPayload(
            event: 'databases.db123.collections.collection123.documents.doc123.create',
            payload: $mockPayload,
            database: $mockDatabase,
            collection: $mockCollection
        );
        
        $this->assertContains('documents', $result['channels']);
        $this->assertContains('databases.db123.collections.collection123.documents', $result['channels']);
        $this->assertContains('databases.db123.collections.collection123.documents.doc123', $result['channels']);

        // Test functions executions event parsing
        $mockProject = new Document(['$id' => 'test-project', 'teamId' => 'team123']);
        $mockPayload = new Document([
            '$id' => 'exec123',
            'functionId' => 'func123',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ]
        ]);
        
        $result = Realtime::fromPayload(
            event: 'functions.func123.executions.exec123.create',
            payload: $mockPayload,
            project: $mockProject
        );
        
        $this->assertContains('console', $result['channels']);
        $this->assertContains('executions', $result['channels']);
        $this->assertContains('executions.exec123', $result['channels']);
        $this->assertContains('functions.func123', $result['channels']);

        // Test buckets files event parsing
        $mockBucket = new Document([
            '$id' => 'bucket123',
            'fileSecurity' => false,
            '$read' => [Role::any()->toString()]
        ]);
        $mockPayload = new Document([
            '$id' => 'file123',
            'bucketId' => 'bucket123'
        ]);
        
        $result = Realtime::fromPayload(
            event: 'buckets.bucket123.files.file123.create',
            payload: $mockPayload,
            bucket: $mockBucket
        );
        
        $this->assertContains('files', $result['channels']);
        $this->assertContains('buckets.bucket123.files', $result['channels']);
        $this->assertContains('buckets.bucket123.files.file123', $result['channels']);
    }
}
