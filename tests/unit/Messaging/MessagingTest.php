<?php

namespace Tests\Unit\Messaging;

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

    public function testUser(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            ['user:123', 'users', 'team:abc', 'team:abc/administrator', 'team:abc/moderator', 'team:def', 'team:def/guest'],
            ['files' => 0, 'documents' => 0, 'documents.789' => 0, 'account.123' => 0]
        );

        $event = [
            'project' => '1',
            'roles' => ['any'],
            'data' => [
                'channels' => [
                    0 => 'account.123',
                ]
            ]
        ];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['users'];

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

        $event['roles'] = ['any'];
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
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => 'test',
                '$collection' => 'collection',
                '$permissions' => [
                    'read(admin)',
                    
                    'update(admin)',
                    'delete(admin)',
                ],
            ]),
            collection: new Document([
                '$id' => 'collection',
                '$permissions' => [
                    'read(any)',
                    'update(any)',
                    'delete(any)',
                ],
            ]),
            database: new Document([
                '$id' => 'database',
            ])
        );

        $this->assertContains('any', $result['roles']);
        $this->assertNotContains('role:admin', $result['roles']);

        /**
         * Test Document Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => 'test',
                '$collection' => 'collection',
                '$permissions' => [
                    'read(any)',
                    'update(any)',
                    'delete(any)',
                ],
            ]),
            collection: new Document([
                '$id' => 'collection',
                '$permissions' => [
                    'read(admin)',
                    
                    'update(admin)',
                    'delete(admin)',
                ],
                'documentSecurity' => true,
            ]),
            database: new Document([
                '$id' => 'database',
            ])
        );

        $this->assertContains('any', $result['roles']);
        $this->assertNotContains('role:admin', $result['roles']);
    }

    public function testFromPayloadBucketLevelPermissions(): void
    {
        /**
         * Test Collection Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.create',
            payload: new Document([
                '$id' => 'test',
                '$collection' => 'bucket',
                '$permissions' => [
                    'read(admin)',
                    
                    'update(admin)',
                    'delete(admin)',
                ],
            ]),
            bucket: new Document([
                '$id' => 'bucket',
                '$permissions' => [
                    'read(any)',
                    'update(any)',
                    'delete(any)',
                ],
            ])
        );

        $this->assertContains('any', $result['roles']);
        $this->assertNotContains('role:admin', $result['roles']);

        /**
         * Test Document Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.create',
            payload: new Document([
                '$id' => 'test',
                '$collection' => 'bucket',
                '$permissions' => [
                    'read(any)',
                    'update(any)',
                    'delete(any)',
                ],
            ]),
            bucket: new Document([
                '$id' => 'bucket',
                '$permissions' => [
                    'read(admin)',
                    
                    'update(admin)',
                    'delete(admin)',
                ],
                'documentSecurity' => true
            ])
        );

        $this->assertContains('any', $result['roles']);
        $this->assertNotContains('role:admin', $result['roles']);
    }
}
