<?php

declare(strict_types=1);

namespace Appwrite\Migration\Version;

use Exception;
use Utopia\Console\Console;
use Utopia\Database\Database;

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
    }
}
