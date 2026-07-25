<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Outputs\Manifest;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base as VideosAction;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Shared behaviour for the two master-manifest endpoints.
 *
 * HLS and DASH get their own routes rather than one `:output` route because the
 * master manifest is the single URL an application hands straight to a player,
 * and Android's ExoPlayer (`Util.inferContentType`) and iOS `AVURLAsset` both
 * infer the container from the URI extension. Keeping `master.m3u8`/`master.mpd`
 * in the path means no client needs an explicit mime-type override.
 *
 * `Base.php` inside an `Http/` directory follows the precedent set by
 * `Modules/Console/Http/Redirects/Base.php` and
 * `Modules/Health/Http/Health/Queue/Base.php`.
 */
abstract class Base extends VideosAction
{
    /**
     * Ready renditions for this video and output, or throws when there are none.
     *
     * @return array<Document>
     */
    protected function getReadyRenditions(
        Database $dbForProject,
        Authorization $authorization,
        Document $video,
        string $output
    ): array {
        $renditions = $authorization->skip(fn () => $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('output', [$output]),
            Query::equal('status', [self::STATUS_READY]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        if (empty($renditions)) {
            throw new Exception(Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        return $renditions;
    }

    /**
     * Ready subtitle tracks for this video.
     *
     * @return array<Document>
     */
    protected function getReadySubtitles(
        Database $dbForProject,
        Authorization $authorization,
        Document $video
    ): array {
        return $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [self::STATUS_READY]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));
    }

    /**
     * Root for every URL inside a manifest.
     *
     * An absolute path rather than an absolute URL: the pre-merge code built these
     * from a hard-coded `TMP_HOST` constant ('http://127.0.0.1/'), which broke
     * behind any proxy, custom domain or TLS terminator. A path is resolved by the
     * player against the manifest's own scheme and authority, so it is correct
     * everywhere without configuration.
     */
    protected function baseUri(Document $video, string $output): string
    {
        return '/v1/videos/' . $video->getId() . '/outputs/' . $output;
    }

    /**
     * Playback routes authenticate via a `project` query parameter (see the
     * `locationAuth` on each SDK method), so every URL a player follows out of a
     * manifest has to carry it forward or the next request has no project context.
     */
    protected function withProject(string $uri, Document $project): string
    {
        return $uri . (\str_contains($uri, '?') ? '&' : '?') . 'project=' . \urlencode($project->getId());
    }

    /**
     * Renders a template from `app/views/videos/` and sends it with an explicit
     * content type.
     */
    protected function sendManifest(Response $response, string $body, string $contentType): void
    {
        $response
            ->setContentType($contentType)
            // Manifests change as renditions come and go, so they must not be cached
            // the way immutable segments are.
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->send($body);
    }

    /**
     * Loads the video and asserts the caller may read its source file.
     */
    protected function authorizeVideo(
        Database $dbForProject,
        Authorization $authorization,
        User $user,
        string $videoId
    ): Document {
        return $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);
    }
}
