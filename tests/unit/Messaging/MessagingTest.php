<?php

namespace Appwrite\Tests;

use Utopia\Database\Document;
use Appwrite\Messaging\Adapter\Realtime;
use PHPUnit\Framework\TestCase;

class MessagingTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testUser()
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            ['user:123', 'role:member', 'team:abc', 'team:abc/administrator', 'team:abc/moderator', 'team:def', 'team:def/guest'],
            ['files' => 0, 'documents' => 0, 'documents.789' => 0, 'account.123' => 0]
        );

        $event = [
            'project' => '1',
            'roles' => ['role:all'],
            'data' => [
                'channels' => [
                    0 => 'account.123',
                ]
            ]
        ];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['role:member'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['user:123'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['team:abc'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['team:abc/administrator'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['team:abc/moderator'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['team:def'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['team:def/guest'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['user:456'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:def/member'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['role:all'];
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

    public function testConvertChannelsGuest()
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

    public function testConvertChannelsUser()
    {
        $user  = new Document([
            '$id' => '123',
            'memberships' => [
                [
                    'teamId' => 'abc',
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    'teamId' => 'def',
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

    public function testFromPayloadCollectionLevelPermissions(): void
    {
        /**
         * Test Collection Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'database.documents.create',
            payload: new Document([
                '$id' => 'test',
                '$collection' => 'collection',
                '$read' => ['role:admin'],
                '$write' => ['role:admin']
            ]),
            collection: new Document([
                '$id' => 'collection',
                '$read' => ['role:all'],
                '$write' => ['role:all'],
                'permission' => 'collection'
            ])
        );

        $this->assertContains('role:all', $result['roles']);
        $this->assertNotContains('role:admin', $result['roles']);

        /**
         * Test Document Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'database.documents.create',
            payload: new Document([
                '$id' => 'test',
                '$collection' => 'collection',
                '$read' => ['role:all'],
                '$write' => ['role:all']
            ]),
            collection: new Document([
                '$id' => 'collection',
                '$read' => ['role:admin'],
                '$write' => ['role:admin'],
                'permission' => 'document'
            ])
        );

        $this->assertContains('role:all', $result['roles']);
        $this->assertNotContains('role:admin', $result['roles']);
    }
}
