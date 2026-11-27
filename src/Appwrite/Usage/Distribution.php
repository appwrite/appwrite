<?php

namespace Appwrite\Usage;

use Appwrite\Distribution\Build\Status as BuildStatus;
use Appwrite\Distribution\Submission\Status as SubmissionStatus;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Utopia\Database\Document;

final class Distribution
{
    public const string SERVICE = 'distribution';
    public const string RESOURCE = 'distributionApp';

    /**
     * Once per build, when it reaches a terminal state. The caller guards it with `meteredAt`.
     */
    public static function publishBuild(
        Context $usage,
        Document $app,
        Document $build,
        Document $project,
        UsagePublisher $publisherForUsage,
    ): void {
        self::scope($usage, $app)
            ->addMetric(METRIC_DISTRIBUTION_BUILDS, 1)
            ->addMetric($build->getAttribute('status') === BuildStatus::Ready->value ? METRIC_DISTRIBUTION_BUILDS_SUCCESS : METRIC_DISTRIBUTION_BUILDS_FAILED, 1);

        self::addCompute($usage, $build);

        if ((($build->getAttribute('toolchain') ?? [])['cache'] ?? false) === true) {
            $usage->addMetric(METRIC_DISTRIBUTION_BUILDS_CACHE_HITS, 1);
        }

        self::publish($usage, $project, $publisherForUsage);
    }

    /**
     * An attempt that is dispatched again still ran, so its compute counts on its own.
     */
    public static function publishAttempt(
        Context $usage,
        Document $app,
        Document $build,
        Document $project,
        UsagePublisher $publisherForUsage,
    ): void {
        self::addCompute(self::scope($usage, $app), $build);

        self::publish($usage, $project, $publisherForUsage);
    }

    public static function publishSigning(
        Context $usage,
        Document $app,
        Document $project,
        UsagePublisher $publisherForUsage,
    ): void {
        self::scope($usage, $app)->addMetric(METRIC_DISTRIBUTION_SIGNING, 1);

        self::publish($usage, $project, $publisherForUsage);
    }

    public static function publishSubmission(
        Context $usage,
        Document $app,
        Document $submission,
        Document $project,
        UsagePublisher $publisherForUsage,
    ): void {
        $published = $submission->getAttribute('status') === SubmissionStatus::Published->value;

        self::scope($usage, $app)
            ->addMetric(METRIC_DISTRIBUTION_SUBMISSIONS, 1)
            ->addMetric($published ? METRIC_DISTRIBUTION_SUBMISSIONS_SUCCESS : METRIC_DISTRIBUTION_SUBMISSIONS_FAILED, 1);

        self::publish($usage, $project, $publisherForUsage);
    }

    public static function addTransfer(Context $usage, Document $app, string $metric, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        self::scope($usage, $app)->addMetric($metric, $bytes);
    }

    /**
     * Queue wait is never counted: a build that never started ran for nothing.
     */
    private static function addCompute(Context $usage, Document $build): void
    {
        $seconds = empty($build->getAttribute('startedAt')) ? 0 : (int) $build->getAttribute('duration', 0);
        if ($seconds <= 0) {
            return;
        }

        $platform = (string) $build->getAttribute('platform', '');

        $usage
            ->addMetric(\str_replace('{platform}', $platform, METRIC_DISTRIBUTION_BUILDS_PLATFORM_COMPUTE), $seconds * 1000)
            ->addMetric(\str_replace('{platform}', $platform, METRIC_DISTRIBUTION_BUILDS_PLATFORM_MB_SECONDS), (int) ((int) $build->getAttribute('memory', 0) * $seconds * (int) $build->getAttribute('cpus', 0)));
    }

    private static function scope(Context $usage, Document $app): Context
    {
        return $usage
            ->setService(self::SERVICE)
            ->setResource(self::RESOURCE)
            ->setResourceId($app->getId())
            ->setResourceInternalId((string) $app->getSequence());
    }

    private static function publish(Context $usage, Document $project, UsagePublisher $publisherForUsage): void
    {
        if ($usage->isEmpty()) {
            return;
        }

        $publisherForUsage->enqueue(new UsageMessage(
            project: $project,
            metrics: $usage->getMetrics(),
            reduce: $usage->getReduce(),
        ));
        $usage->reset();
    }
}
