<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Appwrite\Video\Profiles;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;

class V26 extends Migration
{
    /**
     * Project collections introduced for Videos. Created once as global shared
     * collections (tenant null) so every project on a shared-tables host sees them.
     *
     * @var list<string>
     */
    private const VIDEO_COLLECTIONS = [
        'videos',
        'videos_timelines',
        'videos_renditions',
        'videos_renditions_segments',
        'videos_profiles',
        'videos_captions',
        'videos_captions_segments',
    ];

    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        if ($this->project->getSequence() === 'console') {
            Console::info('Skipping video collections for console project');
            return;
        }

        Console::info('Migrating video collections');
        $this->createVideoCollections();

        Console::info('Seeding default video encode profiles');
        Profiles::seed($this->dbForProject);
    }

    /**
     * Creates the video collections as global shared collections.
     *
     * migrate opens each project with a tenant set. createCollection stamps that
     * tenant onto the _metadata row, which hides the collection from every other
     * project on a shared-tables host and lets project deletion drop the shared
     * table. Create with tenant null so the table and metadata belong to no tenant.
     *
     * @throws Exception|Throwable
     */
    private function createVideoCollections(): void
    {
        $this->dbForProject->withTenant(null, function (): void {
            foreach (self::VIDEO_COLLECTIONS as $collectionId) {
                try {
                    Console::info("Ensuring collection \"{$collectionId}\" exists for project \"{$this->project->getId()}\".");
                    $this->dbForProject->purgeCachedCollection($collectionId);
                    $this->dbForProject->purgeCachedDocument(Database::METADATA, $collectionId);

                    $this->createCollection($collectionId);
                } catch (Throwable $th) {
                    Console::warning("Failed to create collection \"{$collectionId}\": {$th->getMessage()}");

                    // Re-throw so the migration fails fast and doesn't leave the
                    // system in a partially migrated state.
                    throw $th;
                }
            }
        });
    }
}
