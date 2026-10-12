<?php

namespace Appwrite\Video;

use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;

/**
 * Default encode profiles for a project.
 *
 * Renditions are always encoded against a profile, so a project with no
 * profiles cannot transcode anything until one is created by hand. The
 * presets live in app/config/videos-profiles.php. Unique indexes on shared
 * tables lead with _tenant, so each project gets its own ladder when the
 * caller has set the tenant.
 */
final class Profiles
{
    /**
     * Seeds the default encoding ladder into the database's current tenant.
     *
     * Writes skip the database's authorization: project creation and migration
     * run before a user has collection permissions on the new project.
     */
    public static function seed(Database $database): void
    {
        $database->getAuthorization()->skip(function () use ($database): void {
            foreach (Config::getParam('videos-profiles', []) as $profile) {
                try {
                    $database->createDocument('videos_profiles', new Document([
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
        });
    }
}
