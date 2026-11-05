<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Message\Validators;

use Appwrite\Realtime\Message\Validators\SubscribePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscribePayloadTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed, bool}>
     */
    public static function payloads(): \Iterator
    {
        $subscription = ['channels' => ['documents'], 'queries' => ['{"method":"select","values":["*"]}']];
        yield 'one subscription' => [[$subscription], true];
        yield 'subscriptions at the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS, $subscription), true];
        yield 'subscriptions over the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS + 1, $subscription), false];
        yield 'channels at the limit' => [[['channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE, 'documents')]], true];
        yield 'channels over the limit' => [[['channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'documents')]], false];
        yield 'queries at the limit' => [[['channels' => ['documents'], 'queries' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE, 'query')]], true];
        yield 'queries over the limit' => [[['channels' => ['documents'], 'queries' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'query')]], false];
        yield 'not a list' => [['channels' => ['documents']], false];
        yield 'missing channels' => [[['queries' => []]], false];
        yield 'non-string channel' => [[['channels' => [1]]], false];
        yield 'invalid subscription id' => [[['channels' => ['documents'], 'subscriptionId' => '!']], false];
    }

    #[DataProvider('payloads')]
    public function testIsValid(mixed $payload, bool $expected): void
    {
        $this->assertSame($expected, (new SubscribePayload())->isValid($payload));
    }

    public function testDescribesLimit(): void
    {
        $validator = new SubscribePayload();

        $validator->isValid([['channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'documents')]]);

        $this->assertSame('channels must contain at most ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' channels.', $validator->getDescription());
    }
}
