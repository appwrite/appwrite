<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Message\Validators;

use Appwrite\Realtime\Message\Validators\UnsubscribePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnsubscribePayloadTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function payloads(): array
    {
        $subscription = ['subscriptionId' => 'sub'];

        return [
            'one subscription' => [[$subscription], true],
            'subscriptions at the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS, $subscription), true],
            'subscriptions over the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS + 1, $subscription), false],
            'not a list' => [$subscription, false],
            'empty subscription id' => [[['subscriptionId' => '']], false],
        ];
    }

    #[DataProvider('payloads')]
    public function testIsValid(mixed $payload, bool $expected): void
    {
        $this->assertSame($expected, (new UnsubscribePayload())->isValid($payload));
    }
}
