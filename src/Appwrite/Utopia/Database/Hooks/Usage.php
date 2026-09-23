<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Usage\Context as UsageContext;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Lifecycle;

/**
 * Resource counts and storage come from the StatsResources gauges, so write
 * events only count sessions and per-resource-type deployments.
 */
class Usage implements Lifecycle
{
    public function __construct(
        private UsageContext $usage,
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

        match ($data->getCollection()) {
            'sessions' => $this->usage->addMetric(METRIC_SESSIONS, $value),
            'deployments' => $this->trackDeployment($data, $value),
            default => null,
        };
    }

    private function trackDeployment(Document $deployment, int $value): void
    {
        $resourceType = (string) $deployment->getAttribute('resourceType', '');

        $this->usage
            ->addMetric(\str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_DEPLOYMENTS), $value)
            ->addMetric(\str_replace('{resourceType}', $resourceType, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), (int) $deployment->getAttribute('size', 0) * $value);
    }
}
