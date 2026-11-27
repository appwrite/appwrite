<?php

namespace Appwrite\Usage;

use Appwrite\Distribution\Submission\Status as SubmissionStatus;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Utopia\Database\Document;

final class Distribution
{
    public const string SERVICE = 'distribution';
    public const string RESOURCE = 'distributionApp';

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
