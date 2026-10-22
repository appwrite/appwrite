<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Message\Validators;

use Appwrite\Realtime\Message\Validators\SubscribePayload;
use PHPUnit\Framework\TestCase;

final class SubscribePayloadTest extends TestCase
{
    public function testAcceptsPayloadsAtTheLimits(): void
    {
        $validator = new SubscribePayload();

        $this->assertTrue($validator->isValid([['channels' => ['documents']]]));
        $this->assertTrue($validator->isValid(\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS, ['channels' => ['documents']])));
        $this->assertTrue($validator->isValid([[
            'channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE, 'documents'),
            'queries' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE, 'select(["*"])'),
        ]]));
        $this->assertTrue($validator->isValid([['channels' => [\str_repeat('a', APP_LIMIT_ARRAY_ELEMENT_SIZE)]]]));
    }

    public function testRejectsTooManySubscriptions(): void
    {
        $validator = new SubscribePayload();

        $this->assertFalse($validator->isValid(\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS + 1, ['channels' => ['documents']])));
        $this->assertStringContainsString('subscriptions', $validator->getDescription());
    }

    public function testRejectsTooManyChannels(): void
    {
        $validator = new SubscribePayload();

        $this->assertFalse($validator->isValid([['channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'documents')]]));
        $this->assertStringContainsString('channels', $validator->getDescription());
    }

    public function testRejectsLongChannels(): void
    {
        $validator = new SubscribePayload();

        $this->assertFalse($validator->isValid([['channels' => [\str_repeat('a', APP_LIMIT_ARRAY_ELEMENT_SIZE + 1)]]]));
        $this->assertStringContainsString('channel', $validator->getDescription());
    }

    public function testRejectsTooManyQueries(): void
    {
        $validator = new SubscribePayload();

        $this->assertFalse($validator->isValid([[
            'channels' => ['documents'],
            'queries' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'select(["*"])'),
        ]]));
        $this->assertStringContainsString('queries', $validator->getDescription());
    }

    public function testRejectsMalformedPayloads(): void
    {
        $validator = new SubscribePayload();

        $this->assertFalse($validator->isValid('documents'));
        $this->assertFalse($validator->isValid(['channels' => ['documents']]));
        $this->assertFalse($validator->isValid([['queries' => []]]));
        $this->assertFalse($validator->isValid([['channels' => [1]]]));
        $this->assertFalse($validator->isValid([['channels' => ['documents'], 'queries' => 'select']]));
        $this->assertFalse($validator->isValid([['channels' => ['documents'], 'subscriptionId' => '!']]));
    }
}
