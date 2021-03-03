<?php

namespace Appwrite\Tests;

use Appwrite\Database\Document;
use Appwrite\Realtime\Realtime;
use PHPUnit\Framework\TestCase;

class RealtimeGuestTest extends TestCase
{
    public $connections = [];
    public $subscriptions = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testGuest()
    {
        Realtime::setUser(new Document([
            '$id' => ''
        ]));

        $roles = Realtime::getRoles();
        $this->assertCount(1, $roles);
        $this->assertContains('role:guest', $roles);

        $channels = [
            0 => 'files',
            1 => 'documents',
            2 => 'documents.789',
            3 => 'account',
            4 => 'account.456'
        ];

        $channels = Realtime::parseChannels($channels);
        $this->assertCount(3, $channels);
        $this->assertArrayHasKey('files', $channels);
        $this->assertArrayHasKey('documents', $channels);
        $this->assertArrayHasKey('documents.789', $channels);
        $this->assertArrayNotHasKey('account', $channels);
        $this->assertArrayNotHasKey('account.456', $channels);

        Realtime::subscribe('1', 1, $roles, $this->subscriptions, $this->connections, $channels);

 
        $event = [
            'project' => '1',
            'permissions' => ['*'],
            'data' => [
                'channels' => [
                    0 => 'documents',
                    1 => 'documents',
                ]
            ]
        ];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['permissions'] = ['role:guest'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['permissions'] = ['role:member'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['user:123'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:abc'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:abc/administrator'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:abc/god'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:def'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:def/guest'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['user:456'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:def/member'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['permissions'] = ['*'];
        $event['data']['channels'] = ['documents.123'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        $event['data']['channels'] = ['documents.789'];

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['project'] = '2';

        $receivers = Realtime::identifyReceivers(
            $event, 
            $this->subscriptions
        );

        $this->assertEmpty($receivers);

        Realtime::unsubscribe(2, $this->subscriptions, $this->connections);

        $this->assertCount(1, $this->connections);
        $this->assertCount(1, $this->subscriptions['1']);


        Realtime::unsubscribe(1, $this->subscriptions, $this->connections);

        $this->assertEmpty($this->connections);
        $this->assertEmpty($this->subscriptions);
    }
}
