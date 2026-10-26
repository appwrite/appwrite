<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime;

use Appwrite\Realtime\Limits;
use PHPUnit\Framework\TestCase;

final class LimitsTest extends TestCase
{
    public function testFromPlanDefaultsWithoutPlan(): void
    {
        $limits = Limits::fromPlan([]);

        $this->assertSame(APP_LIMIT_REALTIME_SUBSCRIPTIONS, $limits->subscriptions);
        $this->assertSame(APP_LIMIT_REALTIME_CHANNELS, $limits->channels);
        $this->assertSame(APP_LIMIT_REALTIME_QUERIES, $limits->queries);
    }

    public function testFromPlanReadsPlanValues(): void
    {
        $limits = Limits::fromPlan([
            'realtimeSubscriptions' => 20,
            'realtimeChannels' => 50,
            'realtimeQueries' => '30',
            'databasesBatchSize' => 1000,
        ]);

        $this->assertSame(20, $limits->subscriptions);
        $this->assertSame(50, $limits->channels);
        $this->assertSame(30, $limits->queries);
    }

    public function testFromPlanKeepsDefaultsForUnsetValues(): void
    {
        $limits = Limits::fromPlan(['realtimeChannels' => 50]);

        $this->assertSame(APP_LIMIT_REALTIME_SUBSCRIPTIONS, $limits->subscriptions);
        $this->assertSame(50, $limits->channels);
        $this->assertSame(APP_LIMIT_REALTIME_QUERIES, $limits->queries);
    }
}
