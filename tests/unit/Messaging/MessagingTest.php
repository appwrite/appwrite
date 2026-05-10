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

    public function testConvertChannelsRewritesAccountActionSuffixes(): void
    {
        // Authenticated subscriber to `account.{action}` is translated to the
        // user-scoped `account.{userId}.{action}` form so events from other
        // users' accounts don't leak through the literal channel.
        $channels = Realtime::convertChannels(
            ['account.create', 'account.update', 'account.upsert', 'account.delete'],
            '123',
        );

        $this->assertArrayHasKey('account.123.create', $channels);
        $this->assertArrayHasKey('account.123.update', $channels);
        $this->assertArrayHasKey('account.123.upsert', $channels);
        $this->assertArrayHasKey('account.123.delete', $channels);
        $this->assertArrayNotHasKey('account.create', $channels);
        $this->assertArrayNotHasKey('account.update', $channels);
        $this->assertArrayNotHasKey('account.upsert', $channels);
        $this->assertArrayNotHasKey('account.delete', $channels);

        // Other-user channels and unknown action-like suffixes still get stripped.
        $channels = Realtime::convertChannels(
            ['account.other_id', 'account.bogus', 'account.123', 'account.create'],
            '123',
        );
        $this->assertArrayNotHasKey('account.other_id', $channels);
        $this->assertArrayNotHasKey('account.bogus', $channels);
        $this->assertArrayNotHasKey('account.123', $channels);
        $this->assertArrayHasKey('account.123.create', $channels);
    }

    public function testConvertChannelsPreservesAccountActionsForGuest(): void
    {
        // Guests can't scope an action filter to a userId yet, so `account.{action}`
        // is preserved verbatim. fromPayload publishes the unscoped `account.{action}`
        // channel for top-level user events, so the guest's stored form matches and
        // delivers correctly. After the connection authenticates,
        // rebindAccountChannels rewrites the literal to `account.{userId}.{action}`
        // so the action filter survives the auth transition.
        $channels = Realtime::convertChannels(
            ['account.create', 'account.update', 'account.upsert', 'account.delete', 'account'],
            '',
        );

        $this->assertArrayHasKey('account.create', $channels);
        $this->assertArrayHasKey('account.update', $channels);
        $this->assertArrayHasKey('account.upsert', $channels);
        $this->assertArrayHasKey('account.delete', $channels);
        $this->assertArrayHasKey('account', $channels);
    }

    public function testRebindAccountChannelsRemapsAfterReauth(): void
    {
        // Reauth as a different user must remap the user-scoped channels so the
        // connection no longer receives the previous user's account events.
        $rebound = Realtime::rebindAccountChannels(
            ['account.A', 'account.A.create', 'account.A.update', 'documents', 'documents.A.something'],
            'A',
            'B',
        );

        $this->assertContains('account.B', $rebound);
        $this->assertContains('account.B.create', $rebound);
        $this->assertContains('account.B.update', $rebound);
        $this->assertNotContains('account.A', $rebound);
        $this->assertNotContains('account.A.create', $rebound);
        $this->assertNotContains('account.A.update', $rebound);

        // Non-account channels left alone — the rewrite is precise.
        $this->assertContains('documents', $rebound);
        $this->assertContains('documents.A.something', $rebound);
    }

    public function testRebindAccountChannelsIsNoopForUnchangedUser(): void
    {
        // Same user → nothing to rewrite. Avoids unnecessary churn when the
        // permissionsChanged path fires (roles change, userId is constant).
        $channels = ['account.A', 'account.A.create', 'documents'];
        $this->assertSame($channels, Realtime::rebindAccountChannels($channels, 'A', 'A'));
    }

    public function testRebindAccountChannelsIsNoopForEmptyTarget(): void
    {
        // Defensive: if a caller ever passes an empty $newUserId (e.g. a
        // hypothetical in-band logout), we leave channels untouched rather than
        // producing malformed `account.` strings.
        $channels = ['account.A', 'account.A.create', 'account.create', 'documents'];
        $this->assertSame($channels, Realtime::rebindAccountChannels($channels, 'A', ''));
        $this->assertSame($channels, Realtime::rebindAccountChannels($channels, '', ''));
    }

    public function testRebindAccountChannelsPromotesGuestActionFilters(): void
    {
        // Guest connections store `account.{action}` literally (convertChannels
        // preserves the form when userId is empty). On in-band authentication,
        // rebindAccountChannels promotes those literals to user-scoped form so
        // the action filter survives.
        $rebound = Realtime::rebindAccountChannels(
            ['account', 'account.create', 'account.update', 'documents'],
            '',
            'B',
        );

        $this->assertContains('account.B.create', $rebound);
        $this->assertContains('account.B.update', $rebound);
        $this->assertNotContains('account.create', $rebound);
        $this->assertNotContains('account.update', $rebound);

        // Plain `account` and unrelated channels are left alone.
        $this->assertContains('account', $rebound);
        $this->assertContains('documents', $rebound);
    }

    public function testRebindAccountChannelsOnlyRemapsKnownActions(): void
    {
        // Defensive: only suffixes in SUPPORTED_ACTIONS are rewritten, so a
        // channel like `account.A.bogus` stays intact rather than being
        // silently rebound.
        $rebound = Realtime::rebindAccountChannels(
            ['account.A.bogus', 'account.A.create'],
            'A',
            'B',
        );

        $this->assertContains('account.A.bogus', $rebound);
        $this->assertContains('account.B.create', $rebound);
        $this->assertNotContains('account.B.bogus', $rebound);
        $this->assertNotContains('account.A.create', $rebound);
    }

    public function testReauthThenPermissionsChangeThenReauthPreservesAccountAction(): void
    {
        // Full lifecycle, mirrors the auth + permissionsChanged handler logic in
        // app/realtime.php:
        //   1. user A subscribes to account.create (stored as account.A.create)
        //   2. in-band reauth as B → rebound to account.B.create, userId=B
        //   3. permissions-change for B → userId on connection MUST stay 'B'
        //      so a subsequent reauth as C still has previousUserId='B'.
        //   4. reauth as C → rebound to account.C.create, userId=C
        $realtime = new Realtime();

        // Step 1.
        $aChannels = \array_keys(Realtime::convertChannels(['account.create'], 'A'));
        $this->assertSame(['account.A.create'], $aChannels);
        $realtime->subscribe('1', 1, 'sub-1', [Role::user(ID::custom('A'))->toString()], $aChannels, [], 'A');
        $this->assertSame('A', $realtime->connections[1]['userId']);

        // Step 2: A → B.
        $previousUserId = $realtime->connections[1]['userId'];
        $meta = $realtime->getSubscriptionMetadata(1);
        $realtime->unsubscribe(1);
        foreach ($meta as $subId => $sub) {
            $rebound = Realtime::rebindAccountChannels($sub['channels'], $previousUserId, 'B');
            $realtime->subscribe('1', 1, $subId, [Role::user(ID::custom('B'))->toString()], $rebound, [], 'B');
        }
        $this->assertSame('B', $realtime->connections[1]['userId']);
        $this->assertContains('account.B.create', $realtime->connections[1]['channels']);

        // Step 3: permissions-change for B (userId stays 'B').
        $previousUserId = $realtime->connections[1]['userId'];
        $meta = $realtime->getSubscriptionMetadata(1);
        $realtime->unsubscribe(1);
        foreach ($meta as $subId => $sub) {
            $rebound = Realtime::rebindAccountChannels($sub['channels'], $previousUserId, 'B');
            $realtime->subscribe('1', 1, $subId, [Role::user(ID::custom('B'))->toString()], $rebound, [], 'B');
        }
        $this->assertSame('B', $realtime->connections[1]['userId']);
        $this->assertContains('account.B.create', $realtime->connections[1]['channels']);

        // Step 4: B → C.
        $previousUserId = $realtime->connections[1]['userId'];
        $meta = $realtime->getSubscriptionMetadata(1);
        $realtime->unsubscribe(1);
        foreach ($meta as $subId => $sub) {
            $rebound = Realtime::rebindAccountChannels($sub['channels'], $previousUserId, 'C');
            $realtime->subscribe('1', 1, $subId, [Role::user(ID::custom('C'))->toString()], $rebound, [], 'C');
        }
        $this->assertSame('C', $realtime->connections[1]['userId']);
        $this->assertContains('account.C.create', $realtime->connections[1]['channels']);
        $this->assertNotContains('account.B.create', $realtime->connections[1]['channels']);
        $this->assertNotContains('account.A.create', $realtime->connections[1]['channels']);
    }

    public function testGuestAccountActionFilterSurvivesAuthenticationEndToEnd(): void
    {
        // Full lifecycle:
        //   1. Guest connects, subscribes to `account.create`.
        //   2. fromPayload publishes a top-level `users.B.create` event — guest
        //      receives it via the unscoped `account.create` broadcast channel.
        //   3. Guest authenticates as B. Resubscribe goes through
        //      rebindAccountChannels so the same subscription is now scoped to
        //      `account.B.create` and only matches B's events.
        $realtime = new Realtime();

        // Step 1: guest subscribes. convertChannels preserves the literal form.
        $guestChannels = \array_keys(Realtime::convertChannels(['account.create'], ''));
        $this->assertSame(['account.create'], $guestChannels);
        $realtime->subscribe('1', 1, 'sub-1', [Role::guests()->toString()], $guestChannels, [], '');

        // Step 2: fromPayload publishes account.create alongside the user-scoped form.
        $publish = Realtime::fromPayload(
            event: 'users.B.create',
            payload: new Document(['$id' => ID::custom('B')]),
        );
        $this->assertContains('account.create', $publish['channels']);
        $this->assertContains('account.B.create', $publish['channels']);

        // Guest receives the unscoped channel.
        $event = [
            'project' => '1',
            'roles' => [Role::guests()->toString()],
            'data' => [
                'channels' => $publish['channels'],
                'payload' => ['$id' => 'B'],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($event));

        // Step 3: in-band auth promotes the guest to user 'B'.
        $previousUserId = $realtime->connections[1]['userId'] ?? '';
        $meta = $realtime->getSubscriptionMetadata(1);
        $realtime->unsubscribe(1);
        foreach ($meta as $subId => $sub) {
            $rebound = Realtime::rebindAccountChannels($sub['channels'], $previousUserId, 'B');
            $realtime->subscribe('1', 1, $subId, [Role::user(ID::custom('B'))->toString()], $rebound, [], 'B');
        }

        // Literal channel is gone; user-scoped form is in place.
        $this->assertNotContains('account.create', $realtime->connections[1]['channels']);
        $this->assertContains('account.B.create', $realtime->connections[1]['channels']);

        // B-scoped event delivers via the user-scoped channel.
        $bEvent = [
            'project' => '1',
            'roles' => [Role::user(ID::custom('B'))->toString()],
            'data' => [
                'channels' => $publish['channels'],
                'payload' => ['$id' => 'B'],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($bEvent));
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
    public function testFromPayloadEmitsActionSuffixedChannels(): void
    {
        $result = Realtime::fromPayload(
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => ID::custom('document_id'),
                '$collection' => ID::custom('collection_id'),
                '$collectionId' => 'collection_id',
                '$permissions' => [Permission::read(Role::any())],
            ]),
            database: new Document(['$id' => ID::custom('database_id')]),
            collection: new Document([
                '$id' => ID::custom('collection_id'),
                '$permissions' => [Permission::read(Role::any())],
            ])
        );

        // Base channels remain.
        $this->assertContains('documents', $result['channels']);
        $this->assertContains('databases.database_id.collections.collection_id.documents', $result['channels']);
        $this->assertContains('databases.database_id.collections.collection_id.documents.document_id', $result['channels']);

        // Action-suffixed variants are appended for every base channel.
        $this->assertContains('documents.create', $result['channels']);
        $this->assertContains('databases.database_id.collections.collection_id.documents.create', $result['channels']);
        $this->assertContains('databases.database_id.collections.collection_id.documents.document_id.create', $result['channels']);

        // No mismatched action suffixes leak in.
        $this->assertNotContains('documents.update', $result['channels']);
        $this->assertNotContains('documents.delete', $result['channels']);
    }

    public function testFromPayloadEmitsActionSuffixForEveryAction(): void
    {
        foreach (['create', 'update', 'upsert', 'delete'] as $action) {
            $result = Realtime::fromPayload(
                event: "databases.database_id.collections.collection_id.documents.document_id.{$action}",
                payload: new Document([
                    '$id' => ID::custom('document_id'),
                    '$collection' => ID::custom('collection_id'),
                    '$collectionId' => 'collection_id',
                    '$permissions' => [Permission::read(Role::any())],
                ]),
                database: new Document(['$id' => ID::custom('database_id')]),
                collection: new Document([
                    '$id' => ID::custom('collection_id'),
                    '$permissions' => [Permission::read(Role::any())],
                ])
            );

            $this->assertContains("documents.{$action}", $result['channels'], "documents.{$action} missing");
            $this->assertContains(
                "databases.database_id.collections.collection_id.documents.document_id.{$action}",
                $result['channels'],
                "specific-doc {$action} channel missing"
            );
        }
    }

    public function testFromPayloadDoesNotSuffixWhenNoAction(): void
    {
        // Synthetic event without an action segment: e.g. an attribute event whose
        // last segment is not a known action and whose second-to-last segment is
        // also not a known action.
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.update',
            payload: new Document([
                '$id' => ID::custom('file_id'),
                'bucketId' => 'bucket_id',
                '$permissions' => [Permission::read(Role::any())],
            ]),
            bucket: new Document([
                '$id' => ID::custom('bucket_id'),
                '$permissions' => [Permission::read(Role::any())],
            ])
        );

        // Action-suffixed variants for the file event.
        $this->assertContains('files.update', $result['channels']);
        $this->assertContains('buckets.bucket_id.files.update', $result['channels']);
        $this->assertContains('buckets.bucket_id.files.file_id.update', $result['channels']);

        // Base channels remain.
        $this->assertContains('files', $result['channels']);
        $this->assertContains('buckets.bucket_id.files', $result['channels']);
        $this->assertContains('buckets.bucket_id.files.file_id', $result['channels']);
    }

    public function testFromPayloadDoesNotSuffixAdminChannels(): void
    {
        // Function execution event emits resource-leaf channels (executions / functions)
        // alongside admin channels (console / projects.X). Admin channels must NOT
        // get an action suffix — only the resource-leaf channels do.
        $result = Realtime::fromPayload(
            event: 'functions.function_id.executions.execution_id.create',
            payload: new Document([
                '$id' => ID::custom('execution_id'),
                'functionId' => 'function_id',
                '$read' => [Role::any()->toString()],
                '$permissions' => [Permission::read(Role::any())],
            ]),
            project: new Document([
                '$id' => ID::custom('project_id'),
                'teamId' => '123abc',
            ])
        );

        // Resource-leaf channels are suffixed.
        $this->assertContains('executions', $result['channels']);
        $this->assertContains('executions.create', $result['channels']);
        $this->assertContains('executions.execution_id', $result['channels']);
        $this->assertContains('executions.execution_id.create', $result['channels']);
        $this->assertContains('functions.function_id', $result['channels']);
        $this->assertContains('functions.function_id.create', $result['channels']);

        // Admin channels are NOT suffixed.
        $this->assertContains('console', $result['channels']);
        $this->assertNotContains('console.create', $result['channels']);
        $this->assertContains('projects.project_id', $result['channels']);
        $this->assertNotContains('projects.project_id.create', $result['channels']);

        // The bare `functions` channel is never emitted by fromPayload (only
        // `functions.{functionId}` is). The per-function action variant
        // (`functions.{functionId}.create`) is the supported subscription
        // form — bare `functions.create` would be a silent no-op and must
        // therefore NOT appear in the published channel set either.
        $this->assertNotContains('functions', $result['channels']);
        $this->assertNotContains('functions.create', $result['channels']);
    }

    public function testFromPayloadHandlesAttributeTrailingActionEvents(): void
    {
        // `users.[userId].update.{attr}` (e.g. .email, .prefs, .name) — action is the
        // second-to-last segment, not the last one. The suffix must still be `.update`.
        $userResult = Realtime::fromPayload(
            event: 'users.user_id.update.email',
            payload: new Document(['$id' => ID::custom('user_id')])
        );

        $this->assertContains('account', $userResult['channels']);
        $this->assertContains('account.user_id', $userResult['channels']);
        $this->assertContains('account.update', $userResult['channels']);
        $this->assertContains('account.user_id.update', $userResult['channels']);
        // The attribute name must NOT leak into the channel namespace.
        $this->assertNotContains('account.email', $userResult['channels']);
        $this->assertNotContains('account.user_id.email', $userResult['channels']);

        // `teams.[teamId].update.prefs` — same shape at the team level.
        $teamResult = Realtime::fromPayload(
            event: 'teams.team_id.update.prefs',
            payload: new Document(['$id' => ID::custom('team_id')])
        );

        $this->assertContains('teams', $teamResult['channels']);
        $this->assertContains('teams.team_id', $teamResult['channels']);
        $this->assertContains('teams.update', $teamResult['channels']);
        $this->assertContains('teams.team_id.update', $teamResult['channels']);
        $this->assertNotContains('teams.prefs', $teamResult['channels']);
        $this->assertNotContains('teams.team_id.prefs', $teamResult['channels']);

        // `teams.[teamId].memberships.[membershipId].update.{attr}` — same again, deeper.
        $membershipResult = Realtime::fromPayload(
            event: 'teams.team_id.memberships.membership_id.update.status',
            payload: new Document(['$id' => ID::custom('membership_id')])
        );

        $this->assertContains('memberships', $membershipResult['channels']);
        $this->assertContains('memberships.membership_id', $membershipResult['channels']);
        $this->assertContains('memberships.update', $membershipResult['channels']);
        $this->assertContains('memberships.membership_id.update', $membershipResult['channels']);
        $this->assertNotContains('memberships.status', $membershipResult['channels']);
        $this->assertNotContains('memberships.membership_id.status', $membershipResult['channels']);
    }

    public function testFromPayloadDoesNotSuffixAccountForNestedUserEvents(): void
    {
        // Nested user events (challenges/sessions/recovery/verification) emit only
        // user-level account channels in fromPayload. The trailing action belongs to
        // the nested resource, NOT to the user account. A subscriber to
        // `account.create` must not receive `users.U.challenges.C.create` or
        // `users.U.sessions.S.delete` events — that would silently leak unrelated
        // MFA / session traffic into account-level filters.
        foreach (['challenges', 'sessions', 'recovery', 'verification'] as $sub) {
            foreach (['create', 'update', 'delete'] as $action) {
                $result = Realtime::fromPayload(
                    event: "users.user_id.{$sub}.sub_id.{$action}",
                    payload: new Document(['$id' => ID::custom('sub_id')])
                );

                $this->assertContains('account', $result['channels'], "{$sub}.{$action} should still emit base account channel");
                $this->assertContains('account.user_id', $result['channels'], "{$sub}.{$action} should still emit user-scoped account channel");
                $this->assertNotContains("account.{$action}", $result['channels'], "{$sub}.{$action} must NOT leak action suffix onto account channel");
                $this->assertNotContains("account.user_id.{$action}", $result['channels'], "{$sub}.{$action} must NOT leak action suffix onto user-scoped account channel");
            }
        }

        // Top-level user events SHOULD still suffix — guard against an over-eager fix
        // that suppresses the suffix for legitimate account-level CRUD.
        $createResult = Realtime::fromPayload(
            event: 'users.user_id.create',
            payload: new Document(['$id' => ID::custom('user_id')])
        );
        $this->assertContains('account.create', $createResult['channels']);
        $this->assertContains('account.user_id.create', $createResult['channels']);

        $updateResult = Realtime::fromPayload(
            event: 'users.user_id.update.email',
            payload: new Document(['$id' => ID::custom('user_id')])
        );
        $this->assertContains('account.update', $updateResult['channels']);
        $this->assertContains('account.user_id.update', $updateResult['channels']);
    }

    public function testActionSuffixDeliversOnlyMatchingActionEndToEnd(): void
    {
        $realtime = new Realtime();

        // Subscriber A scopes to creates; Subscriber B scopes to deletes.
        $realtime->subscribe('1', 1, 'sub-create', [Role::any()->toString()], ['documents.create']);
        $realtime->subscribe('1', 2, 'sub-delete', [Role::any()->toString()], ['documents.delete']);

        // Simulate what fromPayload would publish for a create event.
        $createEvent = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents', 'documents.create'],
                'payload' => ['$id' => 'doc'],
            ],
        ];
        $createReceivers = $realtime->getSubscribers($createEvent);
        $this->assertArrayHasKey(1, $createReceivers);
        $this->assertArrayNotHasKey(2, $createReceivers);

        // Delete event.
        $deleteEvent = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents', 'documents.delete'],
                'payload' => ['$id' => 'doc'],
            ],
        ];
        $deleteReceivers = $realtime->getSubscribers($deleteEvent);
        $this->assertArrayHasKey(2, $deleteReceivers);
        $this->assertArrayNotHasKey(1, $deleteReceivers);
    }

    public function testPlainChannelStillReceivesAllActionsEndToEnd(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe('1', 1, 'sub-all', [Role::any()->toString()], ['documents']);

        foreach (['create', 'update', 'upsert', 'delete'] as $action) {
            $event = [
                'project' => '1',
                'roles' => [Role::any()->toString()],
                'data' => [
                    'channels' => ['documents', "documents.{$action}"],
                    'payload' => ['$id' => 'doc'],
                ],
            ];
            $this->assertArrayHasKey(1, $realtime->getSubscribers($event), "plain `documents` should match {$action} event");
        }
    }
}
