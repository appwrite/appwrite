<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;

class V25 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        $projectInternalId = $this->project->getSequence();

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        if ($projectInternalId === 'console') {
            return;
        }

        Console::info('Repairing provider trigger attributes');

        foreach (['functions', 'sites'] as $collectionId) {
            Console::log("Repairing collection \"{$collectionId}\"");

            $this->dbForProject->purgeCachedCollection($collectionId);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $collectionId);

            if ($this->dbForProject->getCollection($collectionId)->isEmpty()) {
                Console::warning("Skipping collection \"{$collectionId}\": Collection does not exist");
                continue;
            }

            $this->createAttributesFromCollection(
                $this->dbForProject,
                $collectionId,
                ['providerBranches', 'providerPaths'],
            );

            $this->dbForProject->purgeCachedCollection($collectionId);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $collectionId);
        }
    }
}
