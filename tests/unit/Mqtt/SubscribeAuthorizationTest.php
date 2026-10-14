<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Suback;

/**
 * The subscribe gate: an ordinary topic is granted only to a user entered on its subscriber list,
 * while the reserved users/<id> namespace stays owner-gated and needs no membership. Runs against
 * the in-memory topics/subscribers graph the ReplayTestCase harness wires up.
 */
final class SubscribeAuthorizationTest extends ReplayTestCase
{
    public function testEnteredSubscriberIsGranted(): void
    {
        $this->seedTopic('news', 0);

        $this->assertSame([Packet::QOS_1], $this->subscribeCodes($this->connect(1), 'news'));
    }

    public function testNonSubscriberIsRefused(): void
    {
        $this->seedTopic('news', 0, subscribed: false);

        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'news'));
    }

    public function testUnknownTopicIsRefused(): void
    {
        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'ghost'));
    }

    public function testOrdinaryWildcardIsRefused(): void
    {
        $this->seedTopic('scores/live', 0);

        $this->assertSame([Suback::DENIED], $this->subscribeCodes($this->connect(1), 'scores/+'));
    }

    public function testOwnedUserTopicIsGrantedWithoutMembership(): void
    {
        $name = 'users/' . self::USER;
        $this->seedTopic($name, 0, id: \md5($name), subscribed: false);

        $this->assertSame([Packet::QOS_1], $this->subscribeCodes($this->connect(1), $name));
    }
}
