<?php

namespace Appwrite\Worker;

use Appwrite\Queue\Queue;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Document;

class WorkerMetrics
{
    protected Queue $queue;
    protected string $redisPrefix;
    
    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
        $this->redisPrefix = App::getEnv('_APP_REDIS_PREFIX', '');
    }

    public function getQueueMetrics(string $queueName): array
    {
        try {
            $length = $this->queue->getQueueSize($queueName);
            $processingTime = $this->queue->getAverageProcessingTime($queueName);
            
            return [
                'size' => $length,
                'processing_time' => $processingTime,
                'timestamp' => time(),
            ];
        } catch (\Throwable $th) {
            throw new Exception('Failed to get queue metrics: ' . $th->getMessage());
        }
    }
}