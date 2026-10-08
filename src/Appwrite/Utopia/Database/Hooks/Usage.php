<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Usage\Context as UsageContext;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\BatchCreated;
use Utopia\Database\Event\Document\BatchDeleted;
use Utopia\Database\Event\Document\BatchUpserted;
use Utopia\Database\Event\Document\Created;
use Utopia\Database\Event\Document\Deleted;
use Utopia\Database\Event\Domain;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Hook\Selective;

/**
 * Resource counts and storage come from the StatsResources gauges, so write
 * events only count sessions and per-resource-type deployments.
 */
class Usage implements Lifecycle, Selective
{
    public function __construct(
        private UsageContext $usage,
    ) {
    }

    #[\Override]
    public function handles(Event $event): bool
    {
        return \in_array($event, [
            Event::DocumentCreate,
            Event::DocumentDelete,
            Event::DocumentsCreate,
            Event::DocumentsDelete,
            Event::DocumentsUpsert,
        ], true);
    }

    #[\Override]
    public function handle(Domain $event): void
    {
        match (true) {
            $event instanceof Created => $this->trackDocument($event->collection, $event->document, 1),
            $event instanceof Deleted => $this->trackDocument($event->collection, $event->document, -1),
            $event instanceof BatchCreated => $this->trackBatch($event->collection, $event->count),
            $event instanceof BatchDeleted => $this->trackBatch($event->collection, -1 * $event->count),
            $event instanceof BatchUpserted => $this->trackBatch($event->collection, $event->created),
            default => null,
        };
    }

    private function trackDocument(string $collection, Document $document, int $value): void
    {
        match ($collection) {
            'sessions' => $this->usage->addMetric(METRIC_SESSIONS, $value),
            'deployments' => $this->trackDeployment($document, $value),
            default => null,
        };
    }

    private function trackBatch(string $collection, int $value): void
    {
        if ($collection === 'sessions') {
            $this->usage->addMetric(METRIC_SESSIONS, $value);
        }
    }

    private function trackDeployment(Document $deployment, int $value): void
    {
        $resourceType = (string) $deployment->getAttribute('resourceType', '');
        $owner = \rtrim($resourceType, 's');
        $ownerInternalId = (string) $deployment->getAttribute('resourceInternalId', '');

        $this->usage
            ->addResourceMetric(\str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_DEPLOYMENTS), $value, $owner, $ownerInternalId)
            ->addResourceMetric(\str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), (int) $deployment->getAttribute('size', 0) * $value, $owner, $ownerInternalId);
    }
}
