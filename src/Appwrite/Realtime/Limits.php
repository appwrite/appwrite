<?php

namespace Appwrite\Realtime;

/**
 * How much one realtime connection may subscribe to.
 */
final readonly class Limits
{
    public function __construct(
        public int $subscriptions = APP_LIMIT_REALTIME_SUBSCRIPTIONS,
        public int $channels = APP_LIMIT_REALTIME_CHANNELS,
        public int $queries = APP_LIMIT_REALTIME_QUERIES,
    ) {
    }

    /**
     * A plan may set realtimeSubscriptions, realtimeChannels and realtimeQueries; the rest keep their defaults.
     *
     * @param array<string, mixed> $plan
     */
    public static function fromPlan(array $plan): self
    {
        return new self(
            subscriptions: (int) ($plan['realtimeSubscriptions'] ?? APP_LIMIT_REALTIME_SUBSCRIPTIONS),
            channels: (int) ($plan['realtimeChannels'] ?? APP_LIMIT_REALTIME_CHANNELS),
            queries: (int) ($plan['realtimeQueries'] ?? APP_LIMIT_REALTIME_QUERIES),
        );
    }
}
