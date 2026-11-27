<?php

namespace Appwrite\Platform\Modules\Videos;

/**
 * Drops cached playback segments for one video.
 *
 * CE has no CDN in front of the API, so this does nothing. Cloud replaces the
 * `purgeVideoSegmentCache` resource with a purge of the video Surrogate-Key
 * on the API Fastly service.
 */
class SegmentCache
{
    public function purge(string $projectId, string $videoId): void
    {
    }
}
