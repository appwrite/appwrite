<?php

namespace Appwrite\Usage;

use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Utopia\Database\Document;

/**
 * Emits video usage/billing metrics for a terminal rendition. Mirrors
 * {@see Build} so a rendition is counted identically wherever it settles.
 */
final class Video
{
    /**
     * Record metrics for a settled rendition encode onto the usage context and
     * publish them.
     *
     * The video count itself is not published here — that is a resource gauge
     * owned by the StatsResources worker; publishing it per encode would count
     * rendition attempts (and delete-and-retry cycles) as videos.
     *
     * `videos.storage` is attached to the same message, but StatsUsage skips
     * it. StatsResources recomputes the gauge from each rendition's stored
     * size, so a retry or a deletion replaces the total instead of stacking
     * bytes.
     *
     * Encode compute is published as `executions.mbSeconds` with `service`
     * set to `videos`, the same counter functions and sites use, so the
     * project total includes it and a service filter can show only videos.
     * The value multiplies the encode duration by the worker's specification
     * memory and CPUs.
     *
     * @param int $storageBytes total bytes written to the videos device for this rendition
     * @param int $computeMs    wall-clock encode duration in milliseconds
     * @param array{memory?: int, cpus?: int|float} $specification encode size from app/config/specifications.php
     */
    public static function publish(
        Context $usage,
        Document $video,
        Document $rendition,
        Document $project,
        UsagePublisher $publisherForUsage,
        int $storageBytes = 0,
        int $computeMs = 0,
        array $specification = [],
    ): void {
        $computeMs = \max(0, $computeMs);
        $mbSeconds = (int) (($specification['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * ($computeMs / 1000) * ($specification['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT));

        $usage
            ->setService('videos')
            ->setResource('video')
            ->setResourceInternalId((string) $video->getSequence());

        // Anything that settles as not `ready` — error, abort, a park
        // observed mid-run — counts as failed, so success + failed always adds
        // up to the renditions total.
        if ($rendition->getAttribute('status') === 'ready') {
            $usage->addMetric(METRIC_RENDITIONS_SUCCESS, 1);
        } else {
            $usage->addMetric(METRIC_RENDITIONS_FAILED, 1);
        }

        $usage
            ->addMetric(METRIC_RENDITIONS, 1)
            ->addMetric(METRIC_VIDEOS_STORAGE, \max(0, $storageBytes))
            ->addMetric(METRIC_RENDITIONS_COMPUTE, $computeMs)
            ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, $mbSeconds);

        if (!$usage->isEmpty()) {
            $publisherForUsage->enqueue(new UsageMessage(
                project: $project,
                metrics: $usage->getMetrics(),
                reduce: $usage->getReduce()
            ));
            $usage->reset();
        }
    }
}
