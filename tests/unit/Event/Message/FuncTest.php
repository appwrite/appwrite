<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Func;
use PHPUnit\Framework\TestCase;

final class FuncTest extends TestCase
{
    public function testEnvelopeIdRoundTripsThroughQueuePayload(): void
    {
        $message = Func::fromEvent(
            event: 'users.[userId].create',
            params: ['userId' => 'user-1'],
            payload: ['$id' => 'user-1'],
            envelopeId: 'envelope-1',
        );

        $payload = $message->toArray();
        $restored = Func::fromArray($payload);

        $this->assertSame('envelope-1', $payload['envelopeId']);
        $this->assertSame('envelope-1', $restored->envelopeId);
    }

    public function testLegacyPayloadWithoutEnvelopeIdRemainsSupported(): void
    {
        $restored = Func::fromArray([
            'events' => ['users.user-1.create'],
            'payload' => ['$id' => 'user-1'],
        ]);

        $this->assertSame('', $restored->envelopeId);
    }
}
