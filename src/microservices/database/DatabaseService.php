<?php

namespace Appwrite\Microservices\Database;

use Appwrite\Event\EventDispatcher;
use Appwrite\Database\Database;
use Redis;

class DatabaseService {
    private $eventDispatcher;
    private $database;
    private $redis;

    public function __construct(EventDispatcher $eventDispatcher, Database $database, Redis $redis) {
        $this->eventDispatcher = $eventDispatcher;
        $this->database = $database;
        $this->redis = $redis;
    }

    public function query($projectId, $query) {
        // Check cache first
        $cacheKey = "query:{$projectId}:" . md5($query);
        $cachedResult = $this->redis->get($cacheKey);
        
        if ($cachedResult) {
            return json_decode($cachedResult, true);
        }

        // Get shard for project
        $shard = $this->getShardForProject($projectId);
        $result = $shard->executeQuery($query);

        // Cache result
        $this->redis->setex($cacheKey, 3600, json_encode($result));

        return $result;
    }

    private function getShardForProject($projectId) {
        // Implement sharding logic based on project ID
        $shardId = $projectId % $this->getShardCount();
        return $this->database->getShard($shardId);
    }
}
