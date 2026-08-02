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
     * Record metrics for a terminal rendition (status `ready` or `error`)
     * onto the usage context and publish them.
     *
     * @param int $storageBytes total bytes written to the videos device for this rendition
     * @param int $computeMs    wall-clock encode duration in milliseconds
     */
    public static function publish(
        Context $usage,
        Document $video,
        Document $rendition,
        Document $project,
        UsagePublisher $publisherForUsage,
        int $storageBytes = 0,
        int $computeMs = 0,
    ): void {
        $usage
            ->setResource('video')
            ->setResourceInternalId((string) $video->getSequence());

        switch ($rendition->getAttribute('status')) {
            case 'ready':
                $usage->addMetric(METRIC_RENDITIONS_SUCCESS, 1);
                break;
            case 'error':
                $usage->addMetric(METRIC_RENDITIONS_FAILED, 1);
                break;
        }

        $usage
            ->addMetric(METRIC_VIDEOS, 1)
            ->addMetric(METRIC_RENDITIONS, 1)
            ->addMetric(METRIC_VIDEOS_STORAGE, \max(0, $storageBytes))
            ->addMetric(METRIC_RENDITIONS_COMPUTE, \max(0, $computeMs));

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
