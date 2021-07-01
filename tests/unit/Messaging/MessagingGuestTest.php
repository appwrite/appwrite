<?php

namespace Appwrite\Tests;

use Appwrite\Messaging\Adapter\Realtime;
use PHPUnit\Framework\TestCase;

class MessagingGuestTest extends TestCase
{
    public function testGuest()
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            ['role:guest'],
            ['files' => 0, 'documents' => 0, 'documents.789' => 0, 'account.123' => 0]
        );

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

        $receivers = $realtime->getReceivers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['permissions'] = ['role:guest'];

        $receivers = $realtime->getReceivers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['permissions'] = ['role:member'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['user:123'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:abc'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:abc/administrator'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:abc/god'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:def'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:def/guest'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['user:456'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['team:def/member'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['permissions'] = ['*'];
        $event['data']['channels'] = ['documents.123'];

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $event['data']['channels'] = ['documents.789'];

        $receivers = $realtime->getReceivers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['project'] = '2';

        $receivers = $realtime->getReceivers($event);

        $this->assertEmpty($receivers);

        $realtime->unsubscribe(2);

        $this->assertCount(1, $realtime->connections);
        $this->assertCount(1, $realtime->subscriptions['1']);

        $realtime->unsubscribe(1);

        $this->assertEmpty($realtime->connections);
        $this->assertEmpty($realtime->subscriptions);
    }
}
