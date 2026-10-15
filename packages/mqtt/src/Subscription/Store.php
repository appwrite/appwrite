<?php

namespace Utopia\Mqtt\Subscription;

/**
 * A topic-filter trie for MQTT subscriptions. Subscriptions are indexed by a prefix (an
 * isolation key — a tenant, a project, whatever separates one set of subscriptions from
 * another) and topic level, so a publish resolves its subscribers (with + and # wildcards)
 * in one walk, each fd mapped to its granted QoS.
 *
 * [ROOT] ->
 *      [PREFIX] ->
 *          [LEVEL_0] ->            e.g. 'test', '+'
 *              [LEVEL_1] ->        e.g. 'hello', '+', '#'
 *                  fds: [FD => QOS, ...]
 *
 * Example — subs `test/hello` (fd 11) and `test/#` (fd 12) under prefix P:
 *
 * [ROOT] -> [P] -> ['test'] -> ['hello'] -> fds: [11 => 1]
 *                            -> ['#']     -> fds: [12 => 1]
 */
class Store
{
    /**
     * fd => connection record. `subs` is the fd's topic => granted QoS map.
     *
     * @var array<int, array{prefix: string, userId: string, subs: array<string, int>}>
     */
    private array $connections = [];

    private Node $root;

    public function __construct()
    {
        $this->root = new Node();
    }

    public function subscribe(string $prefix, string $userId, string $topic, int $fd, int $qos = 1): void
    {
        // An fd is one connection under one identity. If it reappears under a different
        // prefix or user, the fd was reused, so purge the previous identity's trie entries
        // first — otherwise unsubscribe()/close() would look under the new identity and leave
        // the old prefix's entries behind as stale subscribers.
        if (isset($this->connections[$fd])
            && ($this->connections[$fd]['prefix'] !== $prefix || $this->connections[$fd]['userId'] !== $userId)) {
            $this->close($fd);
        }

        if (isset($this->connections[$fd]['subs'][$topic])) {
            $this->unsubscribe($topic, $fd);
        }

        $nodes = [$prefix, ...explode('/', $topic)];
        $parent = $this->root;
        foreach ($nodes as $index => $node) {
            if (!$parent->hasNode($node)) {
                $parent->addNode($node, new Node());
            }
            $parent = $parent->nodes[$node];
            if ($index === count($nodes) - 1) {
                $parent->fds[$fd] = $qos;
            }
        }

        if (!isset($this->connections[$fd])) {
            $this->connections[$fd] = [
                'prefix' => $prefix,
                'userId' => $userId,
                'subs' => [],
            ];
        }
        $this->connections[$fd]['subs'][$topic] = $qos;
    }

    public function unsubscribe(string $topic, int $fd): void
    {
        if (!isset($this->connections[$fd]['subs'][$topic])) {
            return;
        }

        $prefix = $this->connections[$fd]['prefix'];
        $nodes = [$prefix, ...explode('/', $topic)];

        $path = [];
        $parent = $this->root;
        foreach ($nodes as $node) {
            if (!$parent->hasNode($node)) {
                $parent = null;
                break;
            }
            $path[] = [$parent, $node];
            $parent = $parent->nodes[$node];
        }

        // Prune the now-dead branch back up to the first still-used node.
        if ($parent !== null) {
            unset($parent->fds[$fd]);

            for ($i = count($path) - 1; $i >= 0; $i--) {
                [$parentNode, $key] = $path[$i];
                $child = $parentNode->nodes[$key];
                if (empty($child->nodes) && empty($child->fds)) {
                    unset($parentNode->nodes[$key]);
                } else {
                    break;
                }
            }
        }

        unset($this->connections[$fd]['subs'][$topic]);
        if (empty($this->connections[$fd]['subs'])) {
            unset($this->connections[$fd]);
        }
    }

    public function close(int $fd): void
    {
        foreach (array_keys($this->connections[$fd]['subs'] ?? []) as $topic) {
            $this->unsubscribe($topic, $fd);
        }
        unset($this->connections[$fd]);
    }

    /**
     * @return array{prefix: string, userId: string, subs: array<string, int>}|null
     */
    public function getConnection(int $fd): ?array
    {
        return $this->connections[$fd] ?? null;
    }

    /**
     * The fds subscribed to a topic under a prefix, each mapped to the highest granted QoS.
     *
     * @return array<int, int> fd => granted QoS
     */
    public function getSubscribers(string $prefix, string $topic): array
    {
        $nodes = [$prefix, ...explode('/', $topic)];

        $current = [$this->root];
        $fds = [];

        $collect = function (Node $node) use (&$fds): void {
            foreach ($node->fds as $fd => $qos) {
                $fds[$fd] = max($fds[$fd] ?? 0, $qos);
            }
        };

        foreach ($nodes as $index => $node) {
            // A wildcard filter that does not itself start with '$' must not match a topic
            // whose first level is a '$'-prefixed system topic (MQTT 4.7.2). Index 0 is the
            // prefix, so index 1 is the topic's first level.
            $systemTopic = $index === 1 && \str_starts_with($node, '$');
            $next = [];

            foreach ($current as $parent) {
                // # matches everything from this level onward
                if (!$systemTopic && $parent->hasNode('#')) {
                    $collect($parent->nodes['#']);
                }

                // exact match
                if ($parent->hasNode($node)) {
                    $next[] = $parent->nodes[$node];
                }

                // + matches exactly one level
                if (!$systemTopic && $parent->hasNode('+')) {
                    $next[] = $parent->nodes['+'];
                }
            }

            $current = $next;

            if (empty($current)) {
                break;
            }
        }

        foreach ($current as $parent) {
            $collect($parent);

            // a trailing #, e.g. sub `a/#` matching publish `a`
            if ($parent->hasNode('#')) {
                $collect($parent->nodes['#']);
            }
        }

        return $fds;
    }
}
