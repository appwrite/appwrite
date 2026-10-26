<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Message\Validators;

use Appwrite\Realtime\Message\Validators\SubscribePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscribePayloadTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function payloads(): array
    {
        $subscription = ['channels' => ['documents'], 'queries' => ['{"method":"select","values":["*"]}']];

        return [
            'one subscription' => [[$subscription], true],
            'subscriptions at the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS, $subscription), true],
            'subscriptions over the limit' => [\array_fill(0, APP_LIMIT_REALTIME_SUBSCRIPTIONS + 1, $subscription), false],
            'channels at the limit' => [[['channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE, 'documents')]], true],
            'channels over the limit' => [[['channels' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'documents')]], false],
            'queries at the limit' => [[['channels' => ['documents'], 'queries' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE, 'query')]], true],
            'queries over the limit' => [[['channels' => ['documents'], 'queries' => \array_fill(0, APP_LIMIT_ARRAY_PARAMS_SIZE + 1, 'query')]], false],
            'not a list' => [['channels' => ['documents']], false],
            'missing channels' => [[['queries' => []]], false],
            'non-string channel' => [[['channels' => [1]]], false],
            'invalid subscription id' => [[['channels' => ['documents'], 'subscriptionId' => '!']], false],
        ];
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
