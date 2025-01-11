<?php

namespace Appwrite\Worker;

use Appwrite\Event\Event;
use Appwrite\Queue\Queue;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Logger\Logger;

class WorkerScalingManager
{
    protected Queue $queue;
    protected WorkerMetrics $metrics;
    protected Logger $logger;

    public function __construct(Queue $queue, WorkerMetrics $metrics, Logger $logger)
    {
        $this->queue = $queue;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function scaleWorkers(string $workerType): void
    {
        try {
            $metrics = $this->metrics->getQueueMetrics($workerType);
            $config = Config::getParam('worker.scaling');
            
            if (!$this->shouldScale($metrics, $config)) {
                return;
            }

            $this->adjustWorkerCount($workerType, $metrics, $config);
            
            $this->logger->info("Worker scaling completed for {$workerType}");
        } catch (\Throwable $th) {
            $this->logger->error("Worker scaling failed: {$th->getMessage()}");
            Event::emit('worker.scaling.error', new Document([
                'worker' => $workerType,
                'error' => $th->getMessage()
            ]));
        }
    }

    protected function shouldScale(array $metrics, array $config): bool
    {
        $lastScaled = $this->getLastScaleTime($workerType);
        if (time() - $lastScaled < $config['cooldown_period']) {
            return false;
        }

        // Histerezis mekanizması
        if ($metrics['size'] > $config['queue_threshold'] * 1.1) {
            return true;
        } elseif ($metrics['size'] < $config['queue_threshold'] * 0.9) {
            return false;
        }

        return false;
    }

    protected function adjustWorkerCount(string $workerType, array $metrics, array $config): void
    {
        $currentInstances = $this->getCurrentWorkerCount($workerType);
        $newInstances = $this->calculateRequiredWorkers($metrics, $config);

        if ($newInstances === $currentInstances) {
            return;
        }

        if ($newInstances > $currentInstances) {
            $this->scaleUp($workerType, $newInstances - $currentInstances);
        } else {
            $this->scaleDown($workerType, $currentInstances - $newInstances);
        }
    }

    protected function getCurrentWorkerCount(string $workerType): int
    {
        // Docker API entegrasyonu burada implement edilecek
        return 1; // Şimdilik default değer
    }

    protected function calculateRequiredWorkers(array $metrics, array $config): int
    {
        $baseCount = ceil($metrics['size'] / $config['queue_threshold']);
        return min(max($baseCount, $config['min_instances']), $config['max_instances']);
    }

    protected function scaleUp(string $workerType, int $count): void
    {
        // Docker API entegrasyonu
        $command = "docker service scale {$workerType}={$count}";
        shell_exec($command);
        $this->logger->info("Scaled up {$workerType} to {$count} instances.");
    }

    protected function scaleDown(string $workerType, int $count): void
    {
        // Docker API entegrasyonu
        $command = "docker service scale {$workerType}={$count}";
        shell_exec($command);
        $this->logger->info("Scaled down {$workerType} to {$count} instances.");
    }
}