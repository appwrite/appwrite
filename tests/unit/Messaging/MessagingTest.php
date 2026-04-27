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
            ID::unique(),
            [
                Role::user(ID::custom('123'))->toString(),
                Role::users()->toString(),
                Role::team(ID::custom('abc'))->toString(),
                Role::team(ID::custom('abc'), 'administrator')->toString(),
                Role::team(ID::custom('abc'), 'moderator')->toString(),
                Role::team(ID::custom('def'))->toString(),
                Role::team(ID::custom('def'), 'guest')->toString(),
            ],
            // Pass plain channel names, Realtime::subscribe will normalize them
            ['files', 'documents', 'documents.789', 'account.123']
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

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::users()->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::user(ID::custom('123'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'), 'administrator')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'), 'moderator')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('def'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('def'), 'guest')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::user(ID::custom('456'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('def'), 'member')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::any()->toString()];
        $event['data']['channels'] = ['documents.123'];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['data']['channels'] = ['documents.789'];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['project'] = '2';

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $realtime->unsubscribe(2);

        $this->assertCount(1, $realtime->connections);
        $this->assertCount(7, $realtime->subscriptions['1']);

        $realtime->unsubscribe(1);

        $this->assertEmpty($realtime->connections);
        $this->assertEmpty($realtime->subscriptions);
    }

    public function testSubscribeUnionsChannelsAndRoles(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::user(ID::custom('123'))->toString()],
            ['documents'],
        );

        $realtime->subscribe(
            '1',
            1,
            'sub-b',
            [Role::users()->toString()],
            ['files'],
        );

        $connection = $realtime->connections[1];

        $this->assertContains('documents', $connection['channels']);
        $this->assertContains('files', $connection['channels']);
        $this->assertContains(Role::user(ID::custom('123'))->toString(), $connection['roles']);
        $this->assertContains(Role::users()->toString(), $connection['roles']);
        $this->assertCount(2, $connection['channels']);
        $this->assertCount(2, $connection['roles']);
    }

    public function testUnsubscribeSubscriptionRemovesOnlyOneSubscription(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::user(ID::custom('123'))->toString()],
            ['documents'],
        );

        $realtime->subscribe(
            '1',
            1,
            'sub-b',
            [Role::users()->toString()],
            ['files'],
        );

        $removed = $realtime->unsubscribeSubscription(1, 'sub-a');

        $this->assertTrue($removed);
        $this->assertArrayHasKey(1, $realtime->connections);

        // sub-a is fully cleaned from the tree
        $this->assertArrayNotHasKey(
            Role::user(ID::custom('123'))->toString(),
            $realtime->subscriptions['1']
        );

        // sub-b still delivers
        $event = [
            'project' => '1',
            'roles' => [Role::users()->toString()],
            'data' => [
                'channels' => ['files'],
            ],
        ];
        $receivers = array_keys($realtime->getSubscribers($event));
        $this->assertEquals([1], $receivers);

        // Channels recomputed: sub-a's channel is gone
        $this->assertSame(['files'], $realtime->connections[1]['channels']);

        // Roles are connection-level auth context — union of both subscribe calls preserved
        $this->assertContains(Role::user(ID::custom('123'))->toString(), $realtime->connections[1]['roles']);
        $this->assertContains(Role::users()->toString(), $realtime->connections[1]['roles']);
    }

    public function testUnsubscribeSubscriptionIsIdempotent(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::users()->toString()],
            ['documents'],
        );

        $this->assertFalse($realtime->unsubscribeSubscription(1, 'does-not-exist'));
        $this->assertFalse($realtime->unsubscribeSubscription(99, 'sub-a'));

        // Original sub is untouched
        $event = [
            'project' => '1',
            'roles' => [Role::users()->toString()],
            'data' => [
                'channels' => ['documents'],
            ],
        ];
        $this->assertEquals([1], array_keys($realtime->getSubscribers($event)));
    }

    public function testUnsubscribeSubscriptionKeepsConnectionWhenLastSubRemoved(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::users()->toString()],
            ['documents'],
        );

        $this->assertTrue($realtime->unsubscribeSubscription(1, 'sub-a'));

        $this->assertArrayHasKey(1, $realtime->connections);
        $this->assertSame([], $realtime->connections[1]['channels']);
        // Roles preserved so a later resubscribe on the same connection still has auth context
        $this->assertSame([Role::users()->toString()], $realtime->connections[1]['roles']);
        $this->assertArrayNotHasKey('1', $realtime->subscriptions);
    }

    public function testResubscribeAfterUnsubscribingLastSubDelivers(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::users()->toString()],
            ['documents'],
        );

        $this->assertTrue($realtime->unsubscribeSubscription(1, 'sub-a'));

        // Simulate the message-based subscribe path reading stored roles
        $storedRoles = $realtime->connections[1]['roles'];
        $this->assertNotEmpty($storedRoles, 'connection roles must survive per-subscription removal');

        $realtime->subscribe('1', 1, 'sub-b', $storedRoles, ['files']);

        $event = [
            'project' => '1',
            'roles' => [Role::users()->toString()],
            'data' => [
                'channels' => ['files'],
            ],
        ];
        $this->assertEquals([1], array_keys($realtime->getSubscribers($event)));
    }

    public function testSubscribeAfterOnOpenEmptySentinelPreservesUnion(): void
    {
        $realtime = new Realtime();

        // Mirrors the onOpen empty-channels path: subscribe with '' id, empty channels
        $realtime->subscribe(
            '1',
            1,
            '',
            [Role::users()->toString()],
            [],
            [],
            'user-123',
        );

        // Now a real subscription comes in via the subscribe message type
        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::user(ID::custom('user-123'))->toString()],
            ['documents'],
        );

        $this->assertSame('user-123', $realtime->connections[1]['userId']);
        $this->assertContains('documents', $realtime->connections[1]['channels']);
        $this->assertContains(Role::users()->toString(), $realtime->connections[1]['roles']);
        $this->assertContains(Role::user(ID::custom('user-123'))->toString(), $realtime->connections[1]['roles']);
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
}
