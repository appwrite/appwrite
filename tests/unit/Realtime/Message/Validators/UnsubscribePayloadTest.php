<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Message\Validators;

use Appwrite\Realtime\Message\Validators\UnsubscribePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnsubscribePayloadTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed, bool}>
     */
    public static function payloads(): \Iterator
    {
        $subscription = ['subscriptionId' => 'sub'];
        yield 'one subscription' => [[$subscription], true];
        yield 'subscriptions at the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS, $subscription), true];
        yield 'subscriptions over the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS + 1, $subscription), false];
        yield 'not a list' => [$subscription, false];
        yield 'empty subscription id' => [[['subscriptionId' => '']], false];
    }

    #[DataProvider('payloads')]
    public function testIsValid(mixed $payload, bool $expected): void
    {
        $this->assertSame($expected, (new UnsubscribePayload())->isValid($payload));
    }
}
