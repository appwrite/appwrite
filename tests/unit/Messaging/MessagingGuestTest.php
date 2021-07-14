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
            'roles' => ['*'],
            'data' => [
                'channels' => [
                    0 => 'documents',
                    1 => 'documents',
                ]
            ]
        ];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['role:guest'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = ['role:member'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['user:123'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:abc'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:abc/administrator'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:abc/god'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:def'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:def/guest'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['user:456'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['team:def/member'];

        $receivers = $realtime->getSubscribers($event);

        $this->assertEmpty($receivers);

        $event['roles'] = ['*'];
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
        $this->assertCount(1, $realtime->subscriptions['1']);

        $realtime->unsubscribe(1);

        $this->assertEmpty($realtime->connections);
        $this->assertEmpty($realtime->subscriptions);
    }
}
