<?php

namespace Utopia\Mqtt\Subscription;

/** One topic-level node in the Store trie. */
class Node
{
    /** @var array<string, Node> child segments keyed by topic level */
    public array $nodes = [];

    /** @var array<int, int> fd => granted QoS for the subscription ending at this node */
    public array $fds = [];

    public function hasNode(string $nodeName): bool
    {
        return isset($this->nodes[$nodeName]);
    }

    public function addNode(string $nodeKey, Node $value): void
    {
        $this->nodes[$nodeKey] = $value;
    }
}
