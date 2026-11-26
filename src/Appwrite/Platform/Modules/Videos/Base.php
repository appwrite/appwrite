<?php

namespace Appwrite\Platform\Modules\Videos;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;

/**
 * Shared behaviour for the Videos module.
 *
 * Lives at the module root rather than under `Http/`, where filenames are
 * restricted to the CRUD set — see `src/Appwrite/Platform/AGENTS.md`. Follows
 * the same placement as `Appwrite\Platform\Modules\Compute\Base`.
 */
abstract class Base extends Action
{
    public const OUTPUT_HLS = 'hls';
    public const OUTPUT_DASH = 'dash';
    public const OUTPUT_CMAF = 'cmaf';

    /** Outputs a rendition can be packaged into. */
    public const OUTPUTS = [self::OUTPUT_HLS, self::OUTPUT_DASH, self::OUTPUT_CMAF];

    public const CODEC_H264 = 'h264';
    public const CODEC_HEVC = 'hevc';
    public const CODEC_VP9 = 'vp9';

    /**
     * Codec ids known to videos-codecs.php. Prefer reading the config at runtime;
     * this list is the compile-time fallback for WhiteList defaults.
     *
     * @var list<string>
     */
    public const CODECS = [self::CODEC_H264, self::CODEC_HEVC, self::CODEC_VP9];

    /**
     * All codec ids declared in videos-codecs.php.
     *
     * @return list<string>
     */
    public static function codecIds(): array
    {
        $codecs = Config::getParam('videos-codecs', []);
        if (!\is_array($codecs) || empty($codecs)) {
            return self::CODECS;
        }

        return \array_values(\array_map('strval', \array_keys($codecs)));
    }

    /**
     * Codec ids with enabled === true.
     *
     * @return list<string>
     */
    public static function enabledCodecs(): array
    {
        $enabled = [];
        foreach (Config::getParam('videos-codecs', []) as $id => $codec) {
            if (\is_array($codec) && ($codec['enabled'] ?? false) === true) {
                $enabled[] = (string) $id;
            }
        }

        return $enabled;
    }

    /**
     * Packaging outputs a codec may be asked for on POST /renditions.
     *
     * @return list<string>
     */
    public static function codecOutputs(string $codec): array
    {
        $codecs = Config::getParam('videos-codecs', []);
        $outputs = $codecs[$codec]['outputs'] ?? [];
        if (!\is_array($outputs)) {
            return [];
        }

        return \array_values(\array_map('strval', $outputs));
    }

    /**
     * Whether $output is allowed for $codec per videos-codecs.php.
     */
    public static function codecSupportsOutput(string $codec, string $output): bool
    {
        return \in_array($output, self::codecOutputs($codec), true);
    }

    /**
     * Normalize a stored codec; empty/missing becomes h264 for pre-migration rows.
     */
    public static function normalizeCodec(?string $codec): string
    {
        $codec = \strtolower(\trim((string) $codec));

        return $codec === '' ? self::CODEC_H264 : $codec;
    }

    /**
     * Reject a codec that is not enabled in config.
     */
    protected function assertCodecEnabled(string $codec): void
    {
        if (!\in_array($codec, self::enabledCodecs(), true)) {
            throw new Exception(Exception::VIDEO_CODEC_DISABLED);
        }
    }

    /**
     * Reject packaging a codec into an output it cannot carry.
     */
    protected function assertCodecSupportsOutput(string $codec, string $output): void
    {
        if (!self::codecSupportsOutput($codec, $output)) {
            throw new Exception(Exception::VIDEO_CODEC_OUTPUT_UNSUPPORTED);
        }
    }

    /**
     * Lifecycle of a rendition or caption, shared with the videos worker.
     *
     * Endpoints create rows as `pending`; the worker advances them and settles on
     * `ready` or `error`.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_STARTED = 'started';
    public const STATUS_ENDED = 'ended';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';
    public const STATUS_ABORTED = 'aborted';
    /** Timeline only: the source has no video track, so there is nothing to tile. */
    public const STATUS_SKIPPED = 'skipped';

    public const JOB_RENDITION = 'rendition';
    public const JOB_TIMELINE = 'timeline';
    public const JOB_CAPTION = 'caption';

    /** Flat root for video worker scratch directories under videos-tmp. */
    public static function tmpJobsRoot(): string
    {
        return \rtrim(APP_STORAGE_VIDEOS_TMP, '/') . '/jobs';
    }

    /**
     * Per-job directory: `{tmpJobsRoot()}/{YmdHi}~{projectId}~{videoId}~{type}-{jobId}`.
     *
     * The leading 12-character UTC minute stamp keeps the name parseable.
     * Encode jobs use the rendition id; timeline and caption jobs use a uniqid.
     */
    public static function tmpJobPath(
        string $projectId,
        string $videoId,
        string $type,
        string $jobId
    ): string {
        $stamp = \gmdate('YmdHi');

        return self::tmpJobsRoot()
            . '/' . $stamp
            . '~' . $projectId
            . '~' . $videoId
            . '~' . $type
            . '-' . $jobId;
    }

    /**
     * True when a job folder belongs to `$projectId`, and to `$videoId` when set.
     *
     * Names are `{YmdHi}~{projectId}~{videoId}~{type}-{jobId}`. The stamp and
     * the `~` after each id are required, so a shorter id cannot match a
     * longer neighbor. An empty `$videoId` matches every job in the project.
     * When `$job` is set (for example `rendition-{id}`), the folder must end
     * with that exact job segment after the video id.
     */
    public static function jobMatches(string $name, string $projectId, string $videoId = '', string $job = ''): bool
    {
        if ($projectId === '' || \strlen($name) < 13 || $name[12] !== '~') {
            return false;
        }

        if (!\ctype_digit(\substr($name, 0, 12))) {
            return false;
        }

        $rest = \substr($name, 13);
        $projectPrefix = $projectId . '~';
        if (!\str_starts_with($rest, $projectPrefix)) {
            return false;
        }

        if ($videoId === '') {
            return $job === '';
        }

        $afterProject = \substr($rest, \strlen($projectPrefix));
        $videoPrefix = $videoId . '~';
        if (!\str_starts_with($afterProject, $videoPrefix)) {
            return false;
        }

        if ($job === '') {
            return true;
        }

        return \substr($afterProject, \strlen($videoPrefix)) === $job;
    }

    /**
     * Bounds for video profile parameters, in kilobits per second and pixels.
     *
     * One set shared by create and update: the pre-merge controller validated
     * create against 32-5000/6-3000 and update against 64-4000/100-2000, so a
     * profile could be created with values its own update endpoint rejected.
     * The range covers common ladder bitrates (360p at 890/64 up to 1080p at
     * 4800/128) with headroom for 8K.
     */
    public const MIN_VIDEO_BITRATE = 32;
    public const MAX_VIDEO_BITRATE = 20000;
    public const MIN_AUDIO_BITRATE = 32;
    public const MAX_AUDIO_BITRATE = 512;
    public const MIN_DIMENSION = 16;
    public const MAX_DIMENSION = 4320;

    /**
     * Profiles store kilobits per second, the unit encoders take; the API takes
     * bits per second, the unit a video's probed bitrates are reported in.
     */
    protected static function toKilobits(string $param, int $bitsPerSecond): int
    {
        if ($bitsPerSecond % 1000 !== 0) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `' . $param . '` param: Value must be in bits per second and a multiple of 1000');
        }

        return \intdiv($bitsPerSecond, 1000);
    }

    /** Videos one project may create within ENCODE_PERIOD. */
    public const VIDEO_LIMIT = 10;

    /**
     * Captions one video may add within ENCODE_PERIOD.
     *
     * Higher than VIDEO_LIMIT / RENDITION_LIMIT: the caption job only downloads
     * a small text file and normalizes it to WebVTT, so it does not compete
     * with encode for CPU the way a rendition does.
     */
    public const CAPTION_LIMIT = 30;

    /** Renditions one video may encode within ENCODE_PERIOD. */
    public const RENDITION_LIMIT = 15;

    /** Window for VIDEO_LIMIT, CAPTION_LIMIT, and RENDITION_LIMIT, in seconds. */
    public const ENCODE_PERIOD = 3600;

    /** Mime types accepted as a transcodable source. */
    public const SOURCE_MIME_PREFIXES = ['video/', 'audio/'];
    public const SOURCE_MIME_TYPES = ['application/ogg'];

    /** Playlists carry an audio/ type but only point at other media. */
    public const SOURCE_MIME_EXCLUDED = [
        'application/vnd.apple.mpegurl',
        'application/x-mpegurl',
        'audio/mpegurl',
        'audio/x-mpegurl',
        'audio/x-scpls',
    ];

    /**
     * Demuxers ffmpeg may read a source with. Playlist and reference formats
     * (hls, dash, concat, ...) are left out so a source cannot open other
     * files or URLs.
     */
    public const SOURCE_FORMATS = [
        'aac', 'ac3', 'aiff', 'amr', 'asf', 'avi', 'caf', 'dv', 'eac3', 'flac', 'flv',
        'h264', 'hevc', 'm4v', 'matroska', 'mov', 'mp3', 'mpeg', 'mpegts', 'mpegvideo',
        'mxf', 'ogg', 'w64', 'wav', 'wv',
    ];

    /** Protocols ffmpeg may open a source with: a local path or a URL. */
    public const SOURCE_PROTOCOLS = ['file', 'http', 'https', 'tls', 'tcp'];

    /**
     * How ffmpeg asks a URL for a source: in ranges of 1 MiB over one
     * connection. Left alone it asks for everything up to the end of the file,
     * which the Storage download endpoint answers with its first 10 MiB. A
     * range has to stay under Response::CHUNK_SIZE as well: the endpoint sends
     * a longer one chunked, without a length, and ffmpeg takes the end of that
     * for the end of the file. It stops there and still exits cleanly, leaving
     * a rendition cut short.
     */
    public const SOURCE_OPTIONS = ['request_size' => 1024 * 1024, 'multiple_requests' => 1];

    /** Mime types accepted as a caption source. */
    public const CAPTION_MIME_TYPES = ['text/vtt', 'text/plain', 'application/x-subrip'];

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
     * Playback routes authenticate via a `project` query parameter (see the
     * `locationAuth` on each SDK method), so every URL a player follows out of a
     * manifest or timeline has to carry it forward or the next request has no
     * project context. `updatedAt` is the video document's last write: a fresh
     * playlist emits a new value and Fastly treats those segment addresses as
     * new objects. Copies that still carry an older time share the video's
     * Surrogate-Key and are dropped by purgeVideoSegmentCache.
     */
    protected function withProject(string $uri, Document $project, Document $video): string
    {
        $query = 'project=' . \urlencode($project->getId())
            . '&updatedAt=' . \urlencode($video->getUpdatedAt());

        return $uri . (\str_contains($uri, '?') ? '&' : '?') . $query;
    }

    /**
     * True when the video is world-readable. Shared caches may store a segment
     * only then: the same URL is 200 for every caller.
     */
    protected function isPublicRead(Document $video): bool
    {
        return \in_array(
            Role::any()->toString(),
            \array_map('strval', $video->getPermissionsByType(PermissionType::Read)),
            true
        );
    }

    /**
     * Surrogate-Key Fastly stores with every public segment of this video.
     * One purge of this name drops the whole ladder, including URLs that still
     * carry an older updatedAt.
     */
    public static function videoSurrogateKey(string $projectId, string $videoId): string
    {
        return \strtolower('video-' . $projectId . '-' . $videoId);
    }

    /**
     * Cache policy for a playback segment whose URL carries the video's updatedAt.
     *
     * A world-readable video may sit at a shared cache; the Surrogate-Key is
     * what one purge uses to drop every copy, including older updatedAt URLs.
     * Anything else stays private so that cache cannot keep serving the URL
     * after access is revoked.
     */
    protected function cachePlaybackSegment(Response $response, Document $project, Document $video): void
    {
        $public = $this->isPublicRead($video);
        $response->addHeader(
            'Cache-Control',
            $public
                ? 'public, max-age=31536000, immutable'
                : 'private, max-age=31536000, immutable'
        );
        if ($public) {
            // CORS echoes Origin with credentials; without Vary, Fastly would
            // reuse the first viewer's origin for every later site.
            $response
                ->addHeader('Vary', 'Origin')
                ->addHeader('Surrogate-Key', self::videoSurrogateKey($project->getId(), $video->getId()));
        }
    }

    /**
     * Loads a video enforcing document `$permissions` for sessions.
     *
     * Privileged users and API keys skip authorization. Empty → VIDEO_NOT_FOUND.
     * Static so play routes and the shared response-cache hook in
     * `app/controllers/shared/api.php` share the same check.
     */
    public static function getAuthorizedVideo(
        Database $dbForProject,
        Authorization $authorization,
        User $user,
        string $videoId
    ): Document {
        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $video = ($isAPIKey || $isPrivilegedUser)
            ? $authorization->skip(fn () => $dbForProject->getDocument('videos', $videoId))
            : $dbForProject->getDocument('videos', $videoId);

        if ($video->isEmpty()) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        return $video;
    }

    /**
     * Asserts the caller may update the parent video before mutating children
     * (renditions, captions). Child rows have no `$permissions`, so writes
     * skip document authorization after this check.
     *
     * Privileged users and API keys skip authorization, matching updateVideo
     * and deleteVideo.
     */
    protected function assertCanUpdateVideo(
        Authorization $authorization,
        User $user,
        Document $video
    ): void {
        $roles = $authorization->getRoles();

        if ($user->isKey($roles) || $user->isPrivileged($roles)) {
            return;
        }

        if (!$authorization->isValid(new Input(PermissionType::Update, $video->getPermissionsByType(PermissionType::Update)))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }
    }

    /**
     * Asserts the caller may delete the video before conflict checks (e.g.
     * in-progress encodes) so unauthorized sessions get 401, not 409.
     */
    protected function assertCanDeleteVideo(
        Authorization $authorization,
        User $user,
        Document $video
    ): void {
        $roles = $authorization->getRoles();

        if ($user->isKey($roles) || $user->isPrivileged($roles)) {
            return;
        }

        if (!$authorization->isValid(new Input(PermissionType::Delete, $video->getPermissionsByType(PermissionType::Delete)))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }
    }

    /**
     * Asserts the caller may read the bucket/file backing a video, and returns
     * the file document.
     *
     * Used when attaching a source or caption/subtitle file (create / update),
     * not for playback. Mirrors
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
        $valid = $authorization->isValid(new Input(PermissionType::Read, $bucket->getPermissionsByType(PermissionType::Read)));

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
     * Deletes a rendition row and enqueues lazy cleanup of its segments and files.
     */
    protected function deleteRendition(
        Database $dbForProject,
        Authorization $authorization,
        DeletePublisher $publisherForDeletes,
        Document $project,
        Document $rendition
    ): void {
        $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videosRenditions', $rendition->getId()));

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
     * Enqueues lazy cleanup of a caption's segments and files.
     *
     * The caller must already have removed the caption row with a status-scoped
     * deleteDocuments so the pending→started claim cannot race past a 409.
     */
    protected function deleteCaption(
        Database $dbForProject,
        Authorization $authorization,
        DeletePublisher $publisherForDeletes,
        Document $project,
        Document $caption
    ): void {
        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $caption,
        ));
    }

    /**
     * Cache policy for caption WebVTT served at a stable URL.
     *
     * The response is permission-gated, so only a private cache may store it.
     * A shared cache would keep serving the URL after access is revoked or the
     * caption is deleted. Captions/Update can replace the source and the worker
     * rewrites the same `captions/{captionId}.vtt` path, so the private lifetime
     * stays short. ETag tracks `$updatedAt`, source file id and size.
     *
     * @return bool true when If-None-Match matched and a 304 was sent
     */
    protected function captionBytesNotModified(
        Request $request,
        Response $response,
        Document $caption
    ): bool {
        $etag = '"' . \sha1(
            $caption->getUpdatedAt()
            . "\0"
            . (string) $caption->getAttribute('fileId', '')
            . "\0"
            . (string) $caption->getAttribute('size', 0)
        ) . '"';

        $response
            ->addHeader('Cache-Control', 'private, max-age=300')
            ->addHeader('ETag', $etag);

        if ($request->getHeaderLine('if-none-match') === $etag) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_MODIFIED)->send('');

            return true;
        }

        return false;
    }

}
