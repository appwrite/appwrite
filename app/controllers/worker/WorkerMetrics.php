<?php

namespace Appwrite\Worker;

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Database\Document;
use Utopia\Registry\Registry;

class WorkerMetrics
{
    protected Cache $cache;
    protected Registry $register;
    
    public function __construct(Cache $cache, Registry $register)
    {
        $this->cache = $cache;
        $this->register = $register;
    }

    /**
     * Collect worker metrics
     */
    public function collectMetrics(string $workerId): array
    {
        $metrics = [
            'queue_length' => $this->getQueueLength($workerId),
            'processing_time' => $this->getAverageProcessingTime($workerId),
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => $this->getCPUUsage(),
            'timestamp' => time()
        ];

        // Store metrics in Redis for monitoring
        $this->cache->set(
            "worker:{$workerId}:metrics",
            json_encode($metrics),
            3600 // 1 hour TTL
        );

        return $metrics;
    }

    /**
     * Get current queue length
     */
    protected function getQueueLength(string $workerId): int
    {
        // Use existing queue metrics from Appwrite
        $queue = $this->register->get('queue');
        return $queue->length() ?? 0;
    }

    /**
     * Get average processing time
     */
    protected function getAverageProcessingTime(string $workerId): float
    {
        $key = "worker:{$workerId}:processing_times";
        $times = $this->cache->get($key);
        
        if (!$times) {
            return 0.0;
        }

        $times = json_decode($times, true);
        return array_sum($times) / count($times);
    }

    /**
     * Get CPU usage
     */
    protected function getCPUUsage(): float
    {
        $load = sys_getloadavg();
        return $load[0]; // 1 minute load average
    }
}
