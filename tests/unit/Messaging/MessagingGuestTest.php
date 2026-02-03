<?php

namespace Tests\Unit\Messaging;

use Appwrite\Messaging\Adapter\Realtime;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class MessagingGuestTest extends TestCase
{
    public function testGuest(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            ID::unique(),
            [Role::guests()->toString()],
            // Pass plain channel names, Realtime::subscribe will normalize them
            ['files', 'documents', 'documents.789', 'account.123']
        );

        $event = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => [
                    0 => 'documents',
                    1 => 'documents',
                ],
                // Non-empty payload so default select(\"*\") subscriptions match
                'payload' => ['_match' => true],
            ]
        ];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::guests()->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::users()->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::user(ID::custom('123'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('abc'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('abc'), 'administrator')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('abc'), 'god')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('def'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('def'), 'guest')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

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
        $this->assertCount(1, $realtime->subscriptions['1']);

        $realtime->unsubscribe(1);

        $this->assertEmpty($realtime->connections);
        $this->assertEmpty($realtime->subscriptions);
    }
}
