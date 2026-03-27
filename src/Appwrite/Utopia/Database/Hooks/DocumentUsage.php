<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Usage\Context as UsageContext;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Lifecycle;

/**
 * Tracks document count metrics per database and collection on user-data document CRUD events.
 *
 * Registered on getDatabasesDB instances.
 */
class DocumentUsage implements Lifecycle
{
    public function __construct(
        private UsageContext $usage,
        private string $documentsMetric,
        private string $databaseIdDocumentsMetric,
        private string $databaseIdCollectionIdDocumentsMetric,
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
        if (!str_starts_with($collection, 'database_') || !str_contains($collection, '_collection_')) {
            return;
        }

        $parts = explode('_', $collection);
        $databaseInternalId = $parts[1] ?? 0;
        $collectionInternalId = $parts[3] ?? 0;

        $this->usage
            ->addMetric($this->documentsMetric, $value)
            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $this->databaseIdDocumentsMetric), $value)
            ->addMetric(str_replace(
                ['{databaseInternalId}', '{collectionInternalId}'],
                [$databaseInternalId, $collectionInternalId],
                $this->databaseIdCollectionIdDocumentsMetric
            ), $value);
    }
}
