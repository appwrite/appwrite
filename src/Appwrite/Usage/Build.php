<?php

namespace Appwrite\Usage;

use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Utopia\Config\Config;
use Utopia\Database\Document;

/**
 * Emits build usage/billing metrics for a completed deployment. Shared by both
 * build backends — the executor (Builds worker) and the jobs-service (Jobs
 * worker) — so a build is counted identically however it was produced.
 */
final class Build
{
    /**
     * Record the metrics for a terminal deployment (status 'ready' or 'failed')
     * onto the usage context and publish them.
     */
    public static function publish(
        Context $usage,
        Document $resource,
        Document $deployment,
        Document $project,
        UsagePublisher $publisherForUsage,
    ): void {
        $spec = Config::getParam('specifications')[$resource->getAttribute('buildSpecification', APP_COMPUTE_SPECIFICATION_DEFAULT)];
        $cpus = (int) ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT);
        $memory = (int) ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT);
        $resourceType = $deployment->getAttribute('resourceType');
        $buildDuration = (int) $deployment->getAttribute('buildDuration', 0) * 1000;
        $mbSeconds = (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus);

        // Per-resource breakdown now travels as resource dimensions on the
        // Context (resolved to resourceType + resourceId in the usage pipeline)
        // instead of per-{resourceInternalId} metric-name templates.
        $usage
            ->setResource(rtrim($resourceType, 's'))
            ->setResourceInternalId((string) $resource->getSequence());

        switch ($deployment->getAttribute('status')) {
            case 'ready':
                $usage
                    ->addMetric(METRIC_BUILDS_SUCCESS, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_SUCCESS, $buildDuration)
                    ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_SUCCESS), 1) // per resource type
                    ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_SUCCESS), $buildDuration);
                break;
            case 'failed':
                $usage
                    ->addMetric(METRIC_BUILDS_FAILED, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_FAILED, $buildDuration)
                    ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_FAILED), 1) // per resource type
                    ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_FAILED), $buildDuration);
                break;
        }

        $usage
            ->addMetric(METRIC_BUILDS, 1) // per project
            ->addMetric(METRIC_BUILDS_STORAGE, $deployment->getAttribute('buildSize', 0))
            ->addMetric(METRIC_BUILDS_COMPUTE, $buildDuration)
            ->addMetric(METRIC_BUILDS_MB_SECONDS, $mbSeconds)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS), 1) // per resource type
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE), $buildDuration)
            ->addMetric(str_replace(['{resourceType}'], [$resourceType], METRIC_RESOURCE_TYPE_BUILDS_MB_SECONDS), $mbSeconds);

        if (! $usage->isEmpty()) {
            $publisherForUsage->enqueue(new UsageMessage(
                project: $project,
                metrics: $usage->getMetrics(),
                reduce: $usage->getReduce()
            ));
            $usage->reset();
        }
    }
}
