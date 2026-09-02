<?php

declare(strict_types=1);

namespace Appwrite\Migration\Version;

use Exception;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Query;

class V26 extends V25
{
    /**
     * Add the immutable attempt ownership fields introduced after V25.
     *
     * @throws Exception
     */
    public function execute(): void
    {
        parent::execute();

        $projectInternalId = $this->project->getSequence();
        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        if ($projectInternalId === 'console') {
            return;
        }

        Console::info('Migrating migration ownership attributes');

        foreach ([
            'databases' => ['migrationId', 'migrationAttemptId'],
            'migrations' => ['attemptId'],
        ] as $collection => $attributes) {
            $this->dbForProject->purgeCachedCollection($collection);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $collection);
            $this->createAttributesFromCollection($this->dbForProject, $collection, $attributes);
            $this->dbForProject->purgeCachedCollection($collection);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $collection);
        }

        foreach ($this->documentsIterator('migrations', [Query::equal('status', ['failed'])]) as $migration) {
            if ($migration->getAttribute('stage') === 'finished') {
                continue;
            }

            try {
                $this->dbForProject->updateDocument('migrations', $migration->getId(), new Document([
                    'stage' => 'finished',
                ]), expectedVersion: $migration->getVersion());
            } catch (Conflict) {
                // A retry claimed this migration after the iterator read it.
            }
        }
    }
}
