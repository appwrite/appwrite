<?php

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Message;

final class MessageTest extends TestCase
{
    public function testADeliveryIsNeverTheLastWhenTheBrokerSetsNoMaximum(): void
    {
        $message = new Message(['pid' => 'pid', 'queue' => 'q', 'timestamp' => 0, 'payload' => []]);

        $this->assertFalse($message->setAttempts(99)->isLastAttempt());
    }

    public function testTheDeliveryThatUsesTheLastAllowedAttemptIsTheLast(): void
    {
        $message = new Message(['pid' => 'pid', 'queue' => 'q', 'timestamp' => 0, 'payload' => []])->setMaxAttempts(3);

        $this->assertFalse($message->setAttempts(1)->isLastAttempt());
        $this->assertTrue($message->setAttempts(2)->isLastAttempt());
        $this->assertTrue((clone $message)->setMaxAttempts(1)->setAttempts(0)->isLastAttempt());
    }
}
