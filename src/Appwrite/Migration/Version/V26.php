<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;

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
        'videos_previews',
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
        $this->seedVideoProfiles();
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

    /**
     * Seeds the default encoding ladder into the current project tenant.
     *
     * Renditions are always encoded against a profile, so a project with no
     * profiles cannot transcode anything until one is created by hand. The
     * presets live in app/config/videos-profiles.php. Unique indexes on shared
     * tables lead with _tenant, so each project gets its own ladder.
     */
    private function seedVideoProfiles(): void
    {
        foreach (Config::getParam('videos-profiles', []) as $profile) {
            try {
                $this->dbForProject->createDocument('videos_profiles', new Document([
                    '$id' => ID::unique(),
                    'name' => $profile['name'],
                    'codec' => $profile['codec'] ?? 'h264',
                    'videoBitRate' => $profile['videoBitRate'],
                    'audioBitRate' => $profile['audioBitRate'],
                    'width' => $profile['width'],
                    'height' => $profile['height'],
                    'search' => $profile['name'],
                ]));
            } catch (Duplicate) {
                // Profile already seeded for this tenant.
            }
        }
    }
}
