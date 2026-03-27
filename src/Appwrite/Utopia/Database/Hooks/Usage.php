<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Usage\Context as UsageContext;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Lifecycle;

/**
 * Tracks resource usage metrics (teams, users, sessions, databases, collections,
 * documents, buckets, files, functions, sites, deployments) on document CRUD events.
 *
 * Registered on dbForProject.
 */
class Usage implements Lifecycle
{
    public function __construct(
        private UsageContext $usage,
        private string $databaseType = '',
    ) {
    }

    public function handle(Event $event, mixed $data): void
    {
        if (!$data instanceof Document) {
            return;
        }

        $value = match ($event) {
            Event::DocumentCreate => 1,
            Event::DocumentDelete => -1,
            Event::DocumentsCreate => $data->getAttribute('modified', 0),
            Event::DocumentsDelete => -1 * $data->getAttribute('modified', 0),
            Event::DocumentsUpsert => $data->getAttribute('created', 0),
            default => null,
        };

        if ($value === null) {
            return;
        }

        $collection = $data->getCollection();

        match (true) {
            $collection === 'teams'
                => $this->usage->addMetric(METRIC_TEAMS, $value),

            $collection === 'users'
                => $this->trackUsers($event, $data, $value),

            $collection === 'sessions'
                => $this->usage->addMetric(METRIC_SESSIONS, $value),

            $collection === 'databases'
                => $this->trackDatabases($event, $data, $value),

            str_starts_with($collection, 'database_') && !str_contains($collection, 'collection')
                => $this->trackCollections($event, $data, $value),

            str_starts_with($collection, 'database_') && str_contains($collection, '_collection_')
                => $this->trackDocuments($data, $value),

            $collection === 'buckets'
                => $this->trackBuckets($event, $data, $value),

            str_starts_with($collection, 'bucket_')
                => $this->trackFiles($data, $value),

            $collection === 'functions'
                => $this->trackFunctions($event, $data, $value),

            $collection === 'sites'
                => $this->trackSites($event, $data, $value),

            $collection === 'deployments'
                => $this->trackDeployments($data, $value),

            default => null,
        };
    }

    private function metric(string $metric): string
    {
        if (
            $this->databaseType === '' ||
            $this->databaseType === DATABASE_TYPE_LEGACY ||
            $this->databaseType === DATABASE_TYPE_TABLESDB
        ) {
            return $metric;
        }

        return $this->databaseType . '.' . $metric;
    }

    private function trackUsers(Event $event, Document $document, int $value): void
    {
        $this->usage->addMetric(METRIC_USERS, $value);
        if ($event === Event::DocumentDelete) {
            $this->usage->addReduce($document);
        }
    }

    private function trackDatabases(Event $event, Document $document, int $value): void
    {
        $this->usage->addMetric($this->metric(METRIC_DATABASES), $value);
        if ($event === Event::DocumentDelete) {
            $this->usage->addReduce($document);
        }
    }

    private function trackCollections(Event $event, Document $document, int $value): void
    {
        $parts = explode('_', $document->getCollection());
        $databaseInternalId = $parts[1] ?? 0;
        $this->usage
            ->addMetric($this->metric(METRIC_COLLECTIONS), $value)
            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $this->metric(METRIC_DATABASE_ID_COLLECTIONS)), $value);

        if ($event === Event::DocumentDelete) {
            $this->usage->addReduce($document);
        }
    }

    private function trackDocuments(Document $document, int $value): void
    {
        $parts = explode('_', $document->getCollection());
        $databaseInternalId = $parts[1] ?? 0;
        $collectionInternalId = $parts[3] ?? 0;
        $this->usage
            ->addMetric($this->metric(METRIC_DOCUMENTS), $value)
            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $this->metric(METRIC_DATABASE_ID_DOCUMENTS)), $value)
            ->addMetric(str_replace(
                ['{databaseInternalId}', '{collectionInternalId}'],
                [$databaseInternalId, $collectionInternalId],
                $this->metric(METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS)
            ), $value);
    }

    private function trackBuckets(Event $event, Document $document, int $value): void
    {
        $this->usage->addMetric(METRIC_BUCKETS, $value);
        if ($event === Event::DocumentDelete) {
            $this->usage->addReduce($document);
        }
    }

    private function trackFiles(Document $document, int $value): void
    {
        $parts = explode('_', $document->getCollection());
        $bucketInternalId = $parts[1];
        $this->usage
            ->addMetric(METRIC_FILES, $value)
            ->addMetric(METRIC_FILES_STORAGE, $document->getAttribute('sizeOriginal') * $value)
            ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES), $value)
            ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES_STORAGE), $document->getAttribute('sizeOriginal') * $value);
    }

    private function trackFunctions(Event $event, Document $document, int $value): void
    {
        $this->usage->addMetric(METRIC_FUNCTIONS, $value);
        if ($event === Event::DocumentDelete) {
            $this->usage->addReduce($document);
        }
    }

    private function trackSites(Event $event, Document $document, int $value): void
    {
        $this->usage->addMetric(METRIC_SITES, $value);
        if ($event === Event::DocumentDelete) {
            $this->usage->addReduce($document);
        }
    }

    private function trackDeployments(Document $document, int $value): void
    {
        $this->usage
            ->addMetric(METRIC_DEPLOYMENTS, $value)
            ->addMetric(METRIC_DEPLOYMENTS_STORAGE, $document->getAttribute('size') * $value)
            ->addMetric(str_replace('{resourceType}', $document->getAttribute('resourceType'), METRIC_RESOURCE_TYPE_DEPLOYMENTS), $value)
            ->addMetric(str_replace('{resourceType}', $document->getAttribute('resourceType'), METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), $document->getAttribute('size') * $value)
            ->addMetric(str_replace(
                ['{resourceType}', '{resourceInternalId}'],
                [$document->getAttribute('resourceType'), $document->getAttribute('resourceInternalId')],
                METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS
            ), $value)
            ->addMetric(str_replace(
                ['{resourceType}', '{resourceInternalId}'],
                [$document->getAttribute('resourceType'), $document->getAttribute('resourceInternalId')],
                METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE
            ), $document->getAttribute('size') * $value);
    }
}
