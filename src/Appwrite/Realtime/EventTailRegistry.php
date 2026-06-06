<?php
namespace Appwrite\Realtime;

use Appwrite\Utopia\Database\RuntimeQuery;
use Utopia\WebSocket\Server;

// token bucket + buffers per subId of a project
class EventTailRegistry{
   /** @var array<string, array{
     * connId:int, 
     * projectId:string, 
     * filters:array,
     * tokens:float, 
     * lastRefill:float, 
     * dropped:int, 
     * delivered:int, 
     * buffer:array<int,array>
     * }> 
     * keyed by subscriptionId */
    private array $subs;

    /** @var array<string, array<string,true>> projectId => set of subIds (hot-path lookup) */
    private array $projectSubscriptionsMap = [];

    /** @var array<int, array<string,true>> connId => set of subIds (cleanup on close) */
    private array $byConnection = [];

    /** @var array<string,string> projectId => teamId (resolved at subscribe) */
    private array $teamCache = [];

    /**
     * @param $rate tokens per second per subscriptions
     * @param $batchMax max frame per flush
     */
    public function __construct(private int $rate=50, private int $batchMax=200) {}
    public function add(int $connId, string $subId, string $projectId, array $filters, float $now): void
    {
        $this->subs[$subId] = [
            'connId'     => $connId,
            'projectId'  => $projectId,
            'filters'   => $filters,   // RuntimeQuery::compile(...) result
            'tokens'     => (float) $this->rate,
            'lastRefill' => $now,
            'dropped'    => 0,
            'delivered'  => 0,
            'buffer'     => [],
        ];
        $this->projectSubscriptionsMap[$projectId][$subId] = true;
        $this->byConnection[$connId][$subId] = true;
    }

    public function remove(string $subId){
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

    public function removeConnection(int $connId){
        foreach (\array_keys($this->byConnection[$connId] ?? []) as $subId) {
            $this->remove($subId);
        }
    }

    public function ingest(string $projectId, array $payload, float $now){
        // filter + sampler
        foreach(array_keys($this->projectSubscriptionsMap[$projectId] ?? []) as $subId){
            $sub = &$this->subs[$subId];

            if(RuntimeQuery::filter($sub['filters'], $payload) === null) continue;

            $elapsed = \max(0.0, $now - $sub['lastRefill']);
            // tokens will be always a fractional value lazily increasing with the rate
            $sub['tokens'] = \min((float) $this->rate, $sub['tokens'] + $elapsed*$this->rate);

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
    public function flush(Server $server){
        foreach ($this->subs as $subId => &$sub) {
            $channel = CONSOLE_TAIL_CHANNEL_PREFIX . '.' . $sub['projectId'];

            if (!empty($sub['buffer'])) {
                // TODO: if count($sub['buffer']) or encoded size could exceed batchMax /
                // setPackageMaxLength(64000), split into chunks and send multiple frames.
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

            // counter frame (rate + dropped) — emit when something was dropped
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
            // TODO: decide reset cadence. Simplest: reset counters every flush.
            $sub['delivered'] = 0;
            $sub['dropped']   = 0;
        }
        unset($sub);
    }
}