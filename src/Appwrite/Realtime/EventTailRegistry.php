<?php

namespace Appwrite\Realtime;

use Appwrite\Utopia\Database\RuntimeQuery;
use Utopia\WebSocket\Server;

// token bucket + buffers per subId of a project per worker
class EventTailRegistry
{
    /** @var array<string, array{
     *   connId:int,
     *   projectId:string,
     *   filters:array<string,mixed>,
     *   tokens:float,
     *   lastRefill:float,
     *   dropped:int,
     *   delivered:int,
     *   buffer:array<int,array<string,mixed>>
     * }> keyed by subscriptionId */
    private array $subs = [];

    /** @var array<string, array<string,true>> projectId => set of subIds (hot-path lookup) */
    private array $projectSubscriptionsMap = [];

    /** @var array<int, array<string,true>> connId => set of subIds (cleanup on close) */
    private array $byConnection = [];

    /** @var array<string,string> projectId => teamId (resolved once per worker at subscribe) */
    private array $teamCache = [];

    /**
     * @param int $rate     tokens per second per subscription
     * @param int $batchMax max frames per flushed websocket frame (kept under setPackageMaxLength)
     */
    public function __construct(private int $rate = 50, private int $batchMax = 200)
    {
    }

    /**
     * Build the tail channel string for a project.
     */
    public static function channel(string $projectId): string
    {
        return CONSOLE_TAIL_CHANNEL_PREFIX . '.' . $projectId;
    }

    public static function projectFromChannel(string $channel): ?string
    {
        $prefix = CONSOLE_TAIL_CHANNEL_PREFIX . '.';
        if (!\str_starts_with($channel, $prefix)) {
            return null;
        }
        $projectId = \substr($channel, \strlen($prefix));
        return $projectId === '' ? null : $projectId;
    }

    public function cachedTeam(string $projectId): ?string
    {
        return $this->teamCache[$projectId] ?? null;
    }

    public function cacheTeam(string $projectId, string $teamId): void
    {
        $this->teamCache[$projectId] = $teamId;
    }

    /**
     * Register a tail subscription owned by this worker.
     *
     * @param array<string,mixed> $filters result of RuntimeQuery::compile()
     */
    public function add(int $connId, string $subId, string $projectId, array $filters, float $now): void
    {
        $this->subs[$subId] = [
            'connId'     => $connId,
            'projectId'  => $projectId,
            'filters'    => $filters,
            'tokens'     => (float) $this->rate,
            'lastRefill' => $now,
            'dropped'    => 0,
            'delivered'  => 0,
            'buffer'     => [],
        ];
        $this->projectSubscriptionsMap[$projectId][$subId] = true;
        $this->byConnection[$connId][$subId] = true;
    }

    public function remove(string $subId): void
    {
        $sub = $this->subs[$subId] ?? null;
        if ($sub === null) {
            return;
        }

        unset($this->projectSubscriptionsMap[$sub['projectId']][$subId]);
        if (empty($this->projectSubscriptionsMap[$sub['projectId']])) {
            unset($this->projectSubscriptionsMap[$sub['projectId']]);
        }

        unset($this->byConnection[$sub['connId']][$subId]);
        if (empty($this->byConnection[$sub['connId']])) {
            unset($this->byConnection[$sub['connId']]);
        }

        unset($this->subs[$subId]);
    }

    public function removeConnection(int $connId): void
    {
        foreach (\array_keys($this->byConnection[$connId] ?? []) as $subId) {
            $this->remove($subId);
        }
    }

    public function isTailed(string $projectId): bool
    {
        return isset($this->projectSubscriptionsMap[$projectId]);
    }

    public function ingest(string $projectId, array $payload, float $now): void
    {
        foreach (\array_keys($this->projectSubscriptionsMap[$projectId] ?? []) as $subId) {
            $sub = &$this->subs[$subId];

            if (RuntimeQuery::filter($sub['filters'], $payload) === null) {
                continue;
            }

            // tokens will be always a fractional value lazily increasing with the rate
            $elapsed = \max(0.0, $now - $sub['lastRefill']);
            $sub['tokens'] = \min((float) $this->rate, $sub['tokens'] + $elapsed * $this->rate);
            $sub['lastRefill'] = $now;

            if ($sub['tokens'] >= 1.0) {
                $sub['tokens'] -= 1.0;
                $sub['buffer'][] = $payload;
                $sub['delivered']++;
            } else {
                $sub['dropped']++;
            }

            unset($sub);
        }
    }

    public function flush(Server $server): void
    {
        foreach ($this->subs as $subId => &$sub) {
            $channel = self::channel($sub['projectId']);

            if (!empty($sub['buffer'])) {
                // Chunk so a single frame stays under the websocket package max length.
                foreach (\array_chunk($sub['buffer'], $this->batchMax) as $chunk) {
                    $server->send([$sub['connId']], (string) \json_encode([
                        'type' => 'event',
                        'data' => [
                            'channels'      => [$channel],
                            'events'        => ['console.tail'],
                            'subscriptions' => [$subId],
                            'payload'       => $chunk,
                        ],
                    ]));
                }
                $sub['buffer'] = [];
            }

            // Counter frame — emit the true rate vs dropped count when sampling kicked in.
            if ($sub['dropped'] > 0) {
                $server->send([$sub['connId']], (string) \json_encode([
                    'type' => 'event',
                    'data' => [
                        'channels'      => [$channel],
                        'events'        => ['console.tail.stats'],
                        'subscriptions' => [$subId],
                        'payload'       => [
                            '$type'     => 'tail.stats',
                            'delivered' => $sub['delivered'],
                            'dropped'   => $sub['dropped'],
                        ],
                    ],
                ]));
            }

            // Reset counters every flush window (~150ms).
            $sub['delivered'] = 0;
            $sub['dropped']   = 0;
        }
        unset($sub);
    }
}
