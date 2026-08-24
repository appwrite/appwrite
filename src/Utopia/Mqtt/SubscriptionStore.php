<?php

namespace Utopia\Mqtt;
class Node{
    public array $nodes = [];
    public array $subId = [];

    public function hasNode(string $nodeName) {
        return isset($this->nodes[$nodeName]);
    }
    public function addNode(string $nodeKey, Node $value){
        $this->nodes[$nodeKey] = $value;    
    }
}

class SubscriptionStore{
    /** @var array<string, array{projectId: string, userId: string, topic: string, fd: int}> subId => record */
    private array $subscriptions = [];
    /** @var array<int, array<string, true>> fd => set of subIds (onClose cleanup) */
    private array $byFd = [];
    private Node $root;

    public function __construct() {
        $this->root = new Node();
    }
    public function upsert(string $projectId, string $userId, string $subId, string $topic, int $fd){
        if(isset($this->subscriptions[$subId])){
            $oldTopic = $this->subscriptions[$subId]['topic'];
            $this->delete($projectId, $userId, $oldTopic, $subId);
        }
        $nodes = [$projectId,...explode("/", $topic)];
        $parent = $this->root;
        foreach ($nodes as $index => $node) {
            if(!$parent->hasNode($node)) $parent->addNode($node, new Node());
            $parent = $parent->nodes[$node];
            if($index === count($nodes) - 1) array_push($parent->subId , $subId);
        }
        $this->subscriptions[$subId] = [
            'projectId' => $projectId,
            'userId' => $userId,
            'topic' => $topic,
            'fd' => $fd,
        ];
        $this->byFd[$fd][$subId] = true;
    }
    public function delete(string $projectId, string $userId, string $oldTopic, string $subId){
        $nodes = [$projectId,...explode("/", $oldTopic)];
        $parent = $this->root;
        foreach ($nodes as $index => $node) {
            if(!$parent->hasNode($node)) break;
            $parent = $parent->nodes[$node];
            if($index === count($nodes) - 1){
                if (($key = array_search($subId, $parent->subId)) !== false) {
                    unset($parent->subId[$key]);
                }
            };
        }
        $fd = $this->subscriptions[$subId]['fd'] ?? null;
        unset($this->subscriptions[$subId]);
        if($fd !== null){
            unset($this->byFd[$fd][$subId]);
            if(empty($this->byFd[$fd])) unset($this->byFd[$fd]);
        }
    }
    public function removeConnection(int $fd){
        foreach(array_keys($this->byFd[$fd] ?? []) as $subId){
            $record = $this->subscriptions[$subId];
            $this->delete($record['projectId'], $record['userId'], $record['topic'], $subId);
        }
    }
    public function hasSubscribers(string $projectId , string $topic) : array | null{
        $nodes = [$projectId,...explode("/", $topic)];
        $parent = $this->root;
        foreach ($nodes as $index => $node) {
            if(!($parent->hasNode($node) || $parent->hasNode('+'))) {
                return null;
            }
            $parent = $parent->nodes[$node];
            if($index === count($nodes) - 1){
                return $parent->subId;
            };
        }
        return null;
    }
}