<?php

namespace Appwrite\Realtime;

use Appwrite\Utopia\Database\RuntimeQuery;
use Utopia\WebSocket\Server;

/**
 * Per-worker token-bucket + buffers for console event-tail subscriptions.
 *
 * Each tail of a single project is one entry, keyed by (connId, subId, projectId).
 */
class EventTailRegistry
{
    /** @var array<int, array<string, array<string, array{
     *   filters:array<string,mixed>,
     *   tokens:float,
     *   lastRefill:float,
     *   dropped:int,
     *   delivered:int,
     *   buffer:array<int,array<string,mixed>>
     * }>>> connId => subId => projectId => state */
    private array $entries = [];

    /** @var array<string, array<int, array<string,true>>> projectId => connId => set of subIds */
    private array $projectIndex = [];

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

    /**
     * Extract the projectId from a `console.tail.<projectId>` channel, or null if the
     * channel isn't a tail channel.
     */
    public static function projectFromChannel(string $channel): ?string
    {
        $prefix = CONSOLE_TAIL_CHANNEL_PREFIX . '.';
        if (!\str_starts_with($channel, $prefix)) {
            return null;
        }
        $projectId = \substr($channel, \strlen($prefix));
        return $projectId === '' ? null : $projectId;
    }

    /**
     * Register a tail of one project under (connId, subId). Additive, mirroring the
     * realtime adapter's subscribe(): adding a new channel never drops channels already
     * associated with the subscription id. Re-adding the SAME (connId, subId, projectId)
     * overwrites in place with fresh state ΓÇö that's how a reused subscription id updates
     * its filter without leaving a stale entry behind. Entries are dropped only by
     * remove()/removeConnection() (i.e. unsubscribe or disconnect), matching the tree.
     *
     * @param array<string,mixed> $filters result of RuntimeQuery::compile()
     */
    public function add(int $connId, string $subId, string $projectId, array $filters, float $now): void
    {
        $this->entries[$connId][$subId][$projectId] = [
            'filters'    => $filters,
            'tokens'     => (float) $this->rate,
            'lastRefill' => $now,
            'dropped'    => 0,
            'delivered'  => 0,
            'buffer'     => [],
        ];
        $this->projectIndex[$projectId][$connId][$subId] = true;
    }

    /**
     * Remove every entry for a (connId, subId) ΓÇö covers a subscription that tailed
     * multiple projects. No-op for unknown subscriptions.
     */
    public function remove(int $connId, string $subId): void
    {
        foreach (\array_keys($this->entries[$connId][$subId] ?? []) as $projectId) {
            $this->detachFromProjectIndex((string) $projectId, $connId, $subId);
        }

        unset($this->entries[$connId][$subId]);
        if (empty($this->entries[$connId])) {
            unset($this->entries[$connId]);
        }
    }

    public function removeConnection(int $connId): void
    {
        foreach (\array_keys($this->entries[$connId] ?? []) as $subId) {
            $this->remove($connId, (string) $subId);
        }
    }

    private function detachFromProjectIndex(string $projectId, int $connId, string $subId): void
    {
        unset($this->projectIndex[$projectId][$connId][$subId]);
        if (empty($this->projectIndex[$projectId][$connId])) {
            unset($this->projectIndex[$projectId][$connId]);
        }
        if (empty($this->projectIndex[$projectId])) {
            unset($this->projectIndex[$projectId]);
        }
    }

    /**
     * O(1) gate for the hot path: does THIS worker hold any tail for the project?
     */
    public function isTailed(string $projectId): bool
    {
        return !empty($this->projectIndex[$projectId]);
    }

    /**
     * For one decoded firehose event: filter FIRST, then sample into each matching
     * entry's buffer. The sampling budget is therefore spent only on events that pass
     * the filter.
     *
     * @param array<string,mixed> $payload compact metadata from Realtime::toTailMetadata()
     */
    public function ingest(string $projectId, array $payload, float $now): void
    {
        foreach (($this->projectIndex[$projectId] ?? []) as $connId => $subs) {
            foreach (\array_keys($subs) as $subId) {
                $entry = &$this->entries[$connId][$subId][$projectId];

                // 1) FILTER FIRST ΓÇö reuse RuntimeQuery against the compact frame
                if (RuntimeQuery::filter($entry['filters'], $payload) === null) {
                    unset($entry);
                    continue;
                }

                // 2) THEN SAMPLE ΓÇö lazy token-bucket refill + consume.
                // tokens is a fractional value that refills with elapsed time, capped at rate.
                $elapsed = \max(0.0, $now - $entry['lastRefill']);
                $entry['tokens'] = \min((float) $this->rate, $entry['tokens'] + $elapsed * $this->rate);
                $entry['lastRefill'] = $now;

                if ($entry['tokens'] >= 1.0) {
                    $entry['tokens'] -= 1.0;
                    $entry['buffer'][] = $payload;
                    $entry['delivered']++;
                } else {
                    $entry['dropped']++;
                }

                unset($entry);
            }
        }
    }

    /**
     * Flush all non-empty buffers as one (or more) batched websocket frame(s) per
     * entry, plus a counter frame whenever events were dropped. Call from a Timer::tick
     * inside onWorkerStart.
     */
    public function flush(Server $server): void
    {
        foreach ($this->entries as $connId => $bySub) {
            foreach ($bySub as $subId => $byProject) {
                foreach (\array_keys($byProject) as $projectId) {
                    $entry = &$this->entries[$connId][$subId][$projectId];
                    $channel = self::channel((string) $projectId);

                    if (!empty($entry['buffer'])) {
                        // Chunk so a single frame stays under the websocket package max length.
                        foreach (\array_chunk($entry['buffer'], $this->batchMax) as $chunk) {
                            $server->send([$connId], (string) \json_encode([
                                'type' => 'event',
                                'data' => [
                                    'channels'      => [$channel],
                                    'events'        => ['console.tail'],
                                    'subscriptions' => [(string) $subId],
                                    'payload'       => $chunk,
                                ],
                            ]));
                        }
                        $entry['buffer'] = [];
                    }

                    // Counter frame ΓÇö emit the true rate vs dropped count when sampling kicked in.
                    if ($entry['dropped'] > 0) {
                        $server->send([$connId], (string) \json_encode([
                            'type' => 'event',
                            'data' => [
                                'channels'      => [$channel],
                                'events'        => ['console.tail.stats'],
                                'subscriptions' => [(string) $subId],
                                'payload'       => [
                                    '$type'     => 'tail.stats',
                                    'delivered' => $entry['delivered'],
                                    'dropped'   => $entry['dropped'],
                                ],
                            ],
                        ]));
                    }

                    // Reset counters every flush window (~150ms).
                    $entry['delivered'] = 0;
                    $entry['dropped']   = 0;
                    unset($entry);
                }
            }
        }
    }
}
