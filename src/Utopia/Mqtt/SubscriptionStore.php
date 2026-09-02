<?php

namespace Utopia\Mqtt;

class Node
{
    /** @var array<string, Node> child segments keyed by topic level */
    public array $nodes = [];
    /** @var array<int, int> fd => granted QoS for the subscription ending at this node */
    public array $fds = [];

    public function hasNode(string $nodeName)
    {
        return isset($this->nodes[$nodeName]);
    }
    public function addNode(string $nodeKey, Node $value)
    {
        $this->nodes[$nodeKey] = $value;
    }
}

class SubscriptionStore
{
    /**
     * fd => connection record
     *
     * @var array<int, array{projectId: string, userId: string, subs: array<string, array{topic: string, qos: int}>}>
     */
    private array $connections = [];

    /**
     *
     * [ROOT] ->
     *      [PROJECT_ID] ->
     *          [LEVEL_0] ->            e.g. 'test', '+'
     *              [LEVEL_1] ->        e.g. 'hello', '+', '#'
     *                  fds: [FD => QOS, ...]
     *
     * Example —> subs `test/hello` (fd 11) and `test/#` (fd 12) under project P:
     *
     * [ROOT] -> [P] -> ['test'] -> ['hello'] -> fds: [11 => 1]
     *                            -> ['#']     -> fds: [12 => 1]
     */
    private Node $root;

    public function __construct()
    {
        $this->root = new Node();
    }

    public function subscribe(string $projectId, string $userId, string $subId, string $topic, int $fd, int $qos = 1)
    {
        if (isset($this->connections[$fd]['subs'][$subId])) {
            $this->unsubscribe($subId, $fd);
        }

        $nodes = [$projectId, ...explode("/", $topic)];
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
                'projectId' => $projectId,
                'userId' => $userId,
                'subs' => [],
            ];
        }
        $this->connections[$fd]['subs'][$subId] = ['topic' => $topic, 'qos' => $qos];
    }

    public function unsubscribe(string $subId, int $fd)
    {
        $topic = $this->connections[$fd]['subs'][$subId]['topic'] ?? null;
        if ($topic === null) {
            return;
        }

        $projectId = $this->connections[$fd]['projectId'];
        $nodes = [$projectId, ...explode("/", $topic)];

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

        // pruning the dead topic
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

        unset($this->connections[$fd]['subs'][$subId]);
        if (empty($this->connections[$fd]['subs'])) {
            unset($this->connections[$fd]);
        }
    }

    public function close(int $fd)
    {
        foreach (array_keys($this->connections[$fd]['subs'] ?? []) as $subId) {
            $this->unsubscribe($subId, $fd);
        }
        unset($this->connections[$fd]);
    }

    /**
     *
     * @return array<int, int> fd => granted QoS
     */
    public function getSubscribers(string $projectId, string $topic): array
    {
        $nodes = [$projectId, ...explode("/", $topic)];

        $current = [$this->root];
        $fds = [];

        $collect = function (Node $node) use (&$fds): void {
            foreach ($node->fds as $fd => $qos) {
                $fds[$fd] = max($fds[$fd] ?? 0, $qos);
            }
        };

        foreach ($nodes as $node) {
            $next = [];

            foreach ($current as $parent) {
                // # matches everything from this level onward
                if ($parent->hasNode('#')) {
                    $collect($parent->nodes['#']);
                }

                // exact match
                if ($parent->hasNode($node)) {
                    $next[] = $parent->nodes[$node];
                }

                // + matches exactly one level
                if ($parent->hasNode('+')) {
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
