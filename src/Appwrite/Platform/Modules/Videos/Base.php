<?php

namespace Appwrite\Platform\Modules\Videos;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\View;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Storage\Device;

/**
 * Shared behaviour for the Videos module.
 *
 * Lives at the module root rather than under `Http/`, where filenames are
 * restricted to the CRUD set — see `src/Appwrite/Platform/AGENTS.md`. Follows
 * the same placement as `Appwrite\Platform\Modules\Compute\Base`.
 */
abstract class Base extends UtopiaAction
{
    public const OUTPUT_HLS = 'hls';
    public const OUTPUT_DASH = 'dash';
    public const OUTPUT_CMAF = 'cmaf';

    /** Outputs a rendition can be packaged into. */
    public const OUTPUTS = [self::OUTPUT_HLS, self::OUTPUT_DASH, self::OUTPUT_CMAF];

    /**
     * Lifecycle of a rendition or subtitle, shared with the videos worker.
     *
     * Endpoints create rows as `waiting`; the worker advances them and settles on
     * `ready` or `error`.
     */
    public const STATUS_WAITING = 'waiting';
    public const STATUS_STARTED = 'started';
    public const STATUS_ENDED = 'ended';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    /**
     * Lifecycle of the video working copy on videos-tmp.
     *
     * Distinct from STATUS_* so rendition/subtitle filters are not polluted
     * with download vocabulary. `ready` is shared with STATUS_READY because
     * both mean "this resource can be used".
     */
    public const SOURCE_PENDING = 'pending';
    public const SOURCE_DOWNLOADING = 'downloading';
    public const SOURCE_READY = 'ready';
    public const SOURCE_REMOVED = 'removed';
    public const SOURCE_ERROR = 'error';

    /** Root of a video's working directory on the shared videos-tmp volume. */
    public static function tmpPath(string $projectId, string $videoId): string
    {
        return \rtrim(APP_STORAGE_VIDEOS_TMP, '/') . '/app-' . $projectId . '/' . $videoId;
    }

    /** The downloaded working copy inside tmpPath(). */
    public static function tmpSourcePath(string $projectId, string $videoId): string
    {
        return self::tmpPath($projectId, $videoId) . '/source';
    }

    /**
     * Storage-style chunk count for a payload of `$bytes`, using the same 5 MB
     * window as file uploads.
     */
    public static function chunkCount(int $bytes): int
    {
        return \max(1, (int) \ceil($bytes / APP_LIMIT_UPLOAD_CHUNK_SIZE));
    }

    /**
     * True when the tmp source exists and its size matches the origin.
     */
    public static function sourceMatches(string $path, int $expected): bool
    {
        return $expected > 0 && \is_file($path) && \filesize($path) === $expected;
    }

    /**
     * True when nothing still needs the tmp source: the download is not
     * running, no rendition is in-flight, and no job directory remains.
     */
    public static function canReleaseSource(string $videoStatus, bool $hasInFlightRendition, bool $jobsRemain): bool
    {
        return $videoStatus !== self::SOURCE_DOWNLOADING
            && !$hasInFlightRendition
            && !$jobsRemain;
    }

    /**
     * Timeline and rendition creates require a live working copy.
     */
    protected function assertSourceReady(Document $video): void
    {
        $status = (string) $video->getAttribute('status', '');

        if ($status === self::SOURCE_REMOVED) {
            throw new Exception(Exception::VIDEO_SOURCE_REMOVED);
        }

        if ($status !== self::SOURCE_READY) {
            throw new Exception(Exception::VIDEO_NOT_READY);
        }
    }

    /**
     * Bounds for video profile parameters, in kilobits per second and pixels.
     *
     * One set shared by create and update: the pre-merge controller validated
     * create against 32-5000/6-3000 and update against 64-4000/100-2000, so a
     * profile could be created with values its own update endpoint rejected.
     * The range spans the seeded presets (360p at 890/64 up to 2160p at
     * 16000/356) with headroom for 8K.
     */
    public const MIN_VIDEO_BITRATE = 32;
    public const MAX_VIDEO_BITRATE = 20000;
    public const MIN_AUDIO_BITRATE = 32;
    public const MAX_AUDIO_BITRATE = 512;
    public const MIN_DIMENSION = 16;
    public const MAX_DIMENSION = 4320;

    /** Mime types accepted as a transcodable source. */
    public const SOURCE_MIME_PREFIXES = ['video/', 'audio/'];
    public const SOURCE_MIME_TYPES = ['application/ogg'];

    /** Mime types accepted as a subtitle source. */
    public const SUBTITLE_MIME_TYPES = ['text/vtt', 'text/plain', 'application/x-subrip'];

    /**
     * Renders one of the `app/views/videos/*.phtml` manifest templates.
     *
     * The repo root is five levels up from this file
     * (src/Appwrite/Platform/Modules/Videos).
     *
     * Rendering is always unminified. HLS playlists and MPDs are line-oriented,
     * and View::render()'s default minifier collapses every whitespace run to a
     * single character — which would fold an entire playlist onto one line.
     *
     * @param array<string, mixed> $params
     */
    protected function renderView(string $template, array $params): string
    {
        $view = new View(__DIR__ . '/../../../../../app/views/videos/' . $template . '.phtml');

        foreach ($params as $key => $value) {
            // Escaping is left to the templates, which call $this->print($value, self::FILTER_ESCAPE)
            // on the fields that need it; blanket-escaping would corrupt URLs and XML.
            $view->setParam($key, $value, false);
        }

        return $view->render(false);
    }

    /**
     * Loads a video, or throws if it does not exist.
     *
     * Video documents are project-internal — they carry no permissions of their
     * own and inherit access from the bucket/file they point at, so reads are
     * done with authorization skipped and gated by assertFileAccess() instead.
     */
    protected function getVideo(Database $dbForProject, Authorization $authorization, string $videoId): Document
    {
        $video = $authorization->skip(fn () => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        return $video;
    }

    /**
     * Asserts the caller may read the bucket/file backing a video, and returns
     * the file document.
     *
     * Static so the shared response-cache revalidation hook in
     * `app/controllers/shared/api.php` can reuse the same check for cached
     * sprite bytes without duplicating the permission logic.
     *
     * This replaces the procedural `validateFilePermissions()` helper the legacy
     * controller declared at file scope. It mirrors
     * `Modules/Storage/Http/Buckets/Files/View/Get.php` — the legacy version
     * gated bucket access on `$mode !== APP_MODE_ADMIN` rather than on roles,
     * which let any admin-mode request through.
     */
    public static function assertFileAccess(
        Database $dbForProject,
        Authorization $authorization,
        User $user,
        string $bucketId,
        string $fileId
    ): Document {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));

        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        if ($fileSecurity && !$valid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        return $file;
    }

    /**
     * Loads a video and asserts read access to its source file in one step.
     */
    protected function getReadableVideo(
        Database $dbForProject,
        Authorization $authorization,
        User $user,
        string $videoId
    ): Document {
        $video = $this->getVideo($dbForProject, $authorization, $videoId);

        self::assertFileAccess(
            $dbForProject,
            $authorization,
            $user,
            $video->getAttribute('bucketId', ''),
            $video->getAttribute('fileId', '')
        );

        return $video;
    }

    /**
     * Remove auto-extracted subtitle tracks that share a language code with an
     * upload. Uploaded rows (non-empty fileId) are never deleted here.
     *
     * @param string|null $exceptId subtitle id to leave alone (e.g. the row being updated)
     */
    protected function deleteEmbeddedSubtitlesForCode(
        Database $dbForProject,
        Authorization $authorization,
        Device $deviceForVideos,
        Document $video,
        string $code,
        ?string $exceptId = null
    ): void {
        $existing = $authorization->skip(fn () => $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('code', [$code]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        foreach ($existing as $subtitle) {
            if ($exceptId !== null && $subtitle->getId() === $exceptId) {
                continue;
            }

            if (!empty($subtitle->getAttribute('fileId', ''))) {
                continue;
            }

            $segments = $authorization->skip(fn () => $dbForProject->find('videos_subtitles_segments', [
                Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));

            foreach ($segments as $segment) {
                $authorization->skip(fn () => $dbForProject->deleteDocument('videos_subtitles_segments', $segment->getId()));
            }

            $authorization->skip(fn () => $dbForProject->deleteDocument('videos_subtitles', $subtitle->getId()));

            $path = $subtitle->getAttribute('path', '');

            if (!empty($path)) {
                try {
                    $deviceForVideos->delete($path);
                } catch (\Throwable) {
                    // Row is gone; stale device bytes are cleaned up with the video.
                }
            }
        }
    }

    /**
     * Deletes a rendition row and enqueues lazy cleanup of its segments and files.
     */
    protected function deleteRendition(
        Database $dbForProject,
        Authorization $authorization,
        DeletePublisher $publisherForDeletes,
        Document $project,
        Document $rendition
    ): void {
        $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videos_renditions', $rendition->getId()));

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove video rendition from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $rendition,
        ));
    }

    /**
     * Deletes a subtitle row and enqueues lazy cleanup of its segments and files.
     */
    protected function deleteSubtitle(
        Database $dbForProject,
        Authorization $authorization,
        DeletePublisher $publisherForDeletes,
        Document $project,
        Document $subtitle
    ): void {
        $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videos_subtitles', $subtitle->getId()));

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove video subtitle from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $subtitle,
        ));
    }

}
