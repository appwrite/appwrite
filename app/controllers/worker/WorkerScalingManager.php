<?php

namespace Appwrite\Worker;

use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Registry\Registry;
use Docker\Docker;
use Docker\API\Model\ContainerConfig;

class WorkerScalingManager
{
    protected Cache $cache;
    protected Registry $register;
    protected Config $config;
    protected WorkerMetrics $metrics;
    
    public function __construct(
        Cache $cache,
        Registry $register,
        Config $config,
        WorkerMetrics $metrics
    ) {
        $this->cache = $cache;
        $this->register = $register;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    /**
     * Check and scale workers if needed
     */
    public function checkAndScale(string $workerType): void
    {
        $config = $this->config->getParam('scaling.workers.' . $workerType);
        if (!$config) {
            return;
        }

        // Get current metrics
        $metrics = $this->metrics->collectMetrics($workerType);
        
        // Check if we're in cooldown period
        $lastScaleTime = $this->cache->get("worker:{$workerType}:last_scale");
        if ($lastScaleTime && (time() - $lastScaleTime) < $config['cooldown_period']) {
            return;
        }

        $currentInstances = $this->getCurrentInstances($workerType);

        if ($this->shouldScaleUp($metrics, $config)) {
            if ($currentInstances < $config['max_instances']) {
                $this->scaleUp($workerType);
            }
        } elseif ($this->shouldScaleDown($metrics, $config)) {
            if ($currentInstances > $config['min_instances']) {
                $this->scaleDown($workerType);
            }
        }
    }

    /**
     * Check if worker should scale up
     */
    protected function shouldScaleUp(array $metrics, array $config): bool
    {
        $threshold = $config['scale_up_threshold'];
        
        return $metrics['queue_length'] > $threshold['queue_length'] ||
               $metrics['cpu_usage'] > $threshold['cpu_usage'] ||
               $metrics['memory_usage'] > $threshold['memory_usage'];
    }

    /**
     * Check if worker should scale down
     */
    protected function shouldScaleDown(array $metrics, array $config): bool
    {
        $threshold = $config['scale_down_threshold'];
        
        return $metrics['queue_length'] < $threshold['queue_length'] &&
               $metrics['cpu_usage'] < $threshold['cpu_usage'] &&
               $metrics['memory_usage'] < $threshold['memory_usage'];
    }

    /**
     * Get current number of worker instances
     */
    protected function getCurrentInstances(string $workerType): int
    {
        // Use Docker API to count current containers
        $docker = Docker::create();
        $containers = $docker->containerList([
            'filters' => [
                'name' => ['appwrite-worker-' . $workerType],
                'status' => ['running']
            ]
        ]);
        
        return count($containers);
    }

    /**
     * Scale up worker instances
     */
    protected function scaleUp(string $workerType): void
    {
        // Log scaling action
        $this->cache->set(
            "worker:{$workerType}:last_scale",
            time(),
            3600
        );

        // Use Docker API to create new container
        $docker = Docker::create();
        
        // Use existing container as template
        $template = $docker->containerInspect('appwrite-worker-' . $workerType);
        
        // Create new container with same config
        $config = new ContainerConfig();
        $config->setImage($template->getConfig()->getImage());
        $config->setCmd($template->getConfig()->getCmd());
        $config->setEnv($template->getConfig()->getEnv());
        
        $docker->containerCreate($config);
    }

    /**
     * Scale down worker instances
     */
    protected function scaleDown(string $workerType): void
    {
        // Log scaling action
        $this->cache->set(
            "worker:{$workerType}:last_scale",
            time(),
            3600
        );

        // Use Docker API to remove container
        $docker = Docker::create();
        $containers = $docker->containerList([
            'filters' => [
                'name' => ['appwrite-worker-' . $workerType],
                'status' => ['running']
            ]
        ]);
        
        if (!empty($containers)) {
            // Remove last container
            $container = end($containers);
            $docker->containerStop($container->getId());
            $docker->containerRemove($container->getId());
        }
    }
}
