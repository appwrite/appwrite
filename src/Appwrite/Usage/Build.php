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

        switch ($deployment->getAttribute('status')) {
            case 'ready':
                $usage
                    ->addMetric(METRIC_BUILDS_SUCCESS, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_SUCCESS, (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_SUCCESS), 1) // per function
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_SUCCESS), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_SUCCESS), 1) // per function
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE_SUCCESS), (int) $deployment->getAttribute('buildDuration', 0) * 1000);
                break;
            case 'failed':
                $usage
                    ->addMetric(METRIC_BUILDS_FAILED, 1) // per project
                    ->addMetric(METRIC_BUILDS_COMPUTE_FAILED, (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_FAILED), 1) // per function
                    ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE_FAILED), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_FAILED), 1) // per function
                    ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE_FAILED), (int) $deployment->getAttribute('buildDuration', 0) * 1000);
                break;
        }

        $usage
            ->addMetric(METRIC_BUILDS, 1) // per project
            ->addMetric(METRIC_BUILDS_STORAGE, $deployment->getAttribute('buildSize', 0))
            ->addMetric(METRIC_BUILDS_COMPUTE, (int) $deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(METRIC_BUILDS_MB_SECONDS, (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus))
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS), 1) // per function
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_COMPUTE), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_BUILDS_MB_SECONDS), (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus))
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS), 1) // per function
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $deployment->getAttribute('buildSize', 0))
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_COMPUTE), (int) $deployment->getAttribute('buildDuration', 0) * 1000)
            ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_MB_SECONDS), (int) ($memory * $deployment->getAttribute('buildDuration', 0) * $cpus));

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
