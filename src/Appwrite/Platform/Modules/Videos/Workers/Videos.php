<?php

namespace Appwrite\Platform\Modules\Videos\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Auth\EncryptionKey;
use Appwrite\Auth\Key;
use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Platform\Modules\Compute\Specification;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\Storage\Encryption;
use Appwrite\Usage\Context;
use Appwrite\Usage\Video as VideoUsage;
use Appwrite\Video\Dash as DashManifest;
use Appwrite\Video\Uploader;
use Captioning\Format\SubripFile;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Telemetry as TelemetryDevice;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\UpDownCounter;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Adapter\FFprobe;
use Utopia\Video\Encoder;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Format\HEVC;
use Utopia\Video\Format\VP9;
use Utopia\Video\Format\X264;
use Utopia\Video\Info;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Package;
use Utopia\Video\Packager;
use Utopia\Video\Progress;
use Utopia\Video\Reporter\Silent;
use Utopia\Video\Representation;
use Utopia\Video\Tile;
use Utopia\Video\Track;
use Utopia\Video\Variant;
use Utopia\Video\Written;

/**
 * Consumes the `videos` queue: thumbnail timelines, caption packaging and
 * rendition transcoding.
 *
 * The class name matches the module because this worker owns the module's
 * primary resource, as `Modules/Databases/Workers/Databases.php` does.
 */
class Videos extends Action
{
    /**
     * Soft text caption codecs that ffmpeg can convert to WebVTT.
     * Image-based streams (PGS, VobSub, …) are skipped.
     *
     * @var list<string>
     */
    private const TEXT_CAPTION_CODECS = [
        'subrip',
        'srt',
        'webvtt',
        'mov_text',
        'text',
        'ass',
        'ssa',
    ];

    /** Packaged media segment length in seconds (HLS / DASH / CMAF). */
    private const SEGMENT_DURATION = 4;

    /** Seconds one embedded caption track may take to extract before it counts as failed. */
    private const SUBTITLE_TIMEOUT = 60;

    /**
     * ffmpeg run limit: ENCODE_BASE + duration x factor, at least ENCODE_FLOOR and
     * at most _APP_VIDEOS_ENCODE_TIMEOUT. A factor of 4 lets an encode run as slowly
     * as 0.25x realtime; frames above 1080p double it.
     *
     * The floor is generous on purpose: a short source at 1080p on a busy node runs
     * well under 0.25x realtime, and a hung encoder is caught by STALL_TIMEOUT long
     * before this limit.
     */
    private const ENCODE_BASE = 600;
    private const ENCODE_FLOOR = 1800;
    private const ENCODE_FACTORS = [
        Base::CODEC_H264 => 4,
        Base::CODEC_HEVC => 8,
        Base::CODEC_VP9 => 10,
    ];

    /** Seconds an encode may report progress without its media time advancing. */
    private const STALL_TIMEOUT = 300;

    /**
     * Lifetime kept on a presigned source beyond the encode cap, for the probe
     * and caption passes that run before it.
     */
    private const PRESIGN_SLACK = 3600;

    /** Longest an ephemeral API key is accepted for, which bounds a source read with one. */
    private const KEY_SECONDS = 86400;

    /** Seconds the API is given to answer for a source before it is copied instead. */
    private const SOURCE_TIMEOUT = 10;

    /** Histogram bucket boundaries in seconds. */
    private const JOB_BUCKETS = [1, 5, 10, 30, 60, 120, 300, 600, 1200, 1800, 3600];
    private const PHASE_BUCKETS = [0.1, 0.5, 1, 5, 10, 30, 60, 120, 300, 600, 1800];
    private const WAIT_BUCKETS = [1, 5, 15, 30, 60, 120, 300, 600, 1800, 3600];

    /** Encode speed buckets as a multiple of realtime. */
    private const SPEED_BUCKETS = [0.1, 0.25, 0.5, 1, 2, 4, 8, 16, 32];

    /**
     * Must be exactly 'videos': app/worker.php derives the queue name
     * (`v1-videos`) and looks the action up by this key.
     */
    public static function getName(): string
    {
        return 'videos';
    }

    public function __construct()
    {
        $this
            ->desc('Videos worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('deviceForFiles')
            ->inject('deviceForVideos')
            ->inject('queueForRealtime')
            ->inject('authorization')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Authorization $authorization,
        Context $usage,
        UsagePublisher $publisherForUsage,
        Telemetry $telemetry,
    ): void {
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $videoMessage = VideoMessage::fromArray($payload);
        $action = $videoMessage->action;

        Span::add('project.id', $project->getId());
        Span::add('video.id', $videoMessage->video->getId());
        Span::add('video.action', $action->value);
        Span::add('queue.name', $message->getQueue());
        Span::add('queue.pid', $message->getPid());
        Span::add('message.timestamp', (string) $message->getTimestamp());
        Span::add('message.attempts', $message->getAttempts());
        Span::add('video.bucket.id', (string) $videoMessage->video->getAttribute('bucketId', ''));
        Span::add('video.file.id', (string) $videoMessage->video->getAttribute('fileId', ''));

        if ($videoMessage->rendition !== null) {
            Span::add('rendition.id', $videoMessage->rendition->getId());
        }
        if ($videoMessage->profile !== null) {
            Span::add('profile.id', $videoMessage->profile->getId());
        }
        if ($videoMessage->caption !== null) {
            Span::add('caption.id', $videoMessage->caption->getId());
        }

        $wait = \max(0, \time() - $message->getTimestamp());
        Span::add('queue.wait', $wait);
        $this->record(
            $telemetry->createHistogram(
                'worker.videos.queue.wait',
                's',
                'Seconds a videos message waited before a worker picked it up',
                ['ExplicitBucketBoundaries' => self::WAIT_BUCKETS]
            ),
            $wait,
            ['action' => $action->value]
        );

        $inflight = $telemetry->createUpDownCounter('worker.videos.inflight', null, 'Videos jobs currently running');
        $this->record($inflight, 1, ['action' => $action->value]);

        try {
            match ($action) {
                VideoAction::Timeline => $this->timeline(
                    $dbForProject,
                    $deviceForFiles,
                    $deviceForVideos,
                    $queueForRealtime,
                    $project,
                    $videoMessage,
                    $telemetry
                ),
                VideoAction::Caption => $this->caption(
                    $dbForProject,
                    $deviceForFiles,
                    $deviceForVideos,
                    $queueForRealtime,
                    $project,
                    $videoMessage,
                    $telemetry
                ),
                VideoAction::Encode => $this->encode(
                    $dbForProject,
                    $deviceForFiles,
                    $deviceForVideos,
                    $queueForRealtime,
                    $usage,
                    $publisherForUsage,
                    $project,
                    $videoMessage,
                    $telemetry
                ),
            };
        } finally {
            $this->record($inflight, -1, ['action' => $action->value]);
        }
    }

    /**
     * Probe the source, tile thumbnail sheets and emit a relative WebVTT timeline.
     */
    private function timeline(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Document $project,
        VideoMessage $videoMessage,
        Telemetry $telemetry
    ): void {
        $video = $videoMessage->video;
        $projectId = $videoMessage->project->getId();
        $workspace = null;
        $permissions = $this->readPermissions($video);
        $started = \microtime(true);
        $stage = 'claim';
        $outcome = 'error';
        // Ownership token: claim and reclaim bump $updatedAt. Probe and
        // captionsExtracted also rewrite the video row, so refresh after
        // prepareSource. Terminal writes require the token to still match.
        $claimUpdatedAt = '';

        try {
            $this->log('Videos worker: timeline started for video ' . $video->getId());
            $current = $dbForProject->getDocument('videos', $video->getId());
            if ($current->isEmpty()) {
                $outcome = 'halted';
                return;
            }

            $rowStatus = (string) $current->getAttribute('timelineStatus', '');
            if ($rowStatus === Base::STATUS_PENDING) {
                // Compare-and-swap: only one updateDocuments may leave pending.
                // A deleted video or a stale redelivery updates nothing and stops
                // before download or tiling.
                $updated = $dbForProject->updateDocuments(
                    'videos',
                    new Document([
                        'timelineStatus' => Base::STATUS_STARTED,
                        'timelineError' => null,
                    ]),
                    [
                        Query::equal('$id', [$video->getId()]),
                        Query::equal('timelineStatus', [Base::STATUS_PENDING]),
                    ]
                );
                if ($updated === 0) {
                    $current = $dbForProject->getDocument('videos', $video->getId());
                    $outcome = $current->isEmpty() ? 'halted' : 'duplicate';
                    return;
                }
            } elseif ($rowStatus === Base::STATUS_STARTED) {
                $priorUpdatedAt = (string) $current->getUpdatedAt();
                $updated = $dbForProject->updateDocuments(
                    'videos',
                    new Document([
                        'timelineStatus' => Base::STATUS_STARTED,
                        'timelineError' => null,
                    ]),
                    [
                        Query::equal('$id', [$video->getId()]),
                        Query::equal('timelineStatus', [Base::STATUS_STARTED]),
                        Query::equal('$updatedAt', [$priorUpdatedAt]),
                    ]
                );
                if ($updated === 0) {
                    $current = $dbForProject->getDocument('videos', $video->getId());
                    $outcome = $current->isEmpty() ? 'halted' : 'duplicate';
                    return;
                }
                $this->log('Timeline for video ' . $video->getId() . ' reclaimed from started');
            } else {
                $outcome = 'duplicate';
                return;
            }

            $video = $dbForProject->getDocument('videos', $video->getId());
            if ($video->isEmpty()) {
                $outcome = 'halted';
                return;
            }
            $claimUpdatedAt = (string) $video->getUpdatedAt();

            $workspace = $this->jobWorkspace(
                $projectId,
                $video->getId(),
                Base::JOB_TIMELINE,
                \uniqid('', true)
            );
            $stage = 'download';
            [$video, $inPath, $headers] = $this->prepareSource(
                $dbForProject,
                $deviceForFiles,
                $deviceForVideos,
                $video,
                $workspace,
                $queueForRealtime,
                $project,
                $permissions,
                $telemetry,
                VideoAction::Timeline->value,
                $stage
            );
            // Probe / captionsExtracted rewrite the video row; refresh the token.
            $claimUpdatedAt = (string) $video->getUpdatedAt();

            $this->notify(
                $queueForRealtime,
                $project,
                $video,
                'videos.[videoId].timelines.update',
                ['videoId' => $video->getId()],
                $permissions
            );

            $encoder = $this->encoder($this->timeout($video, 1), $headers);

            // Prefer dimensions over bitrate: many containers (VBR MKV, some DivX)
            // report width/height but leave bitrate as 0, and empty(0) is true in PHP.
            $width = (int) $video->getAttribute('width', 0);
            $height = (int) $video->getAttribute('height', 0);

            if ($width <= 0 || $height <= 0) {
                Span::add('warning.message', 'Source has no video track; timeline skipped');
                $parked = $this->timelineStatus($dbForProject, $video, Base::STATUS_SKIPPED, null, $claimUpdatedAt);
                if ($parked->isEmpty()) {
                    $outcome = 'reclaimed';
                    return;
                }
                $outcome = 'skipped';
                $this->notify(
                    $queueForRealtime,
                    $project,
                    $parked,
                    'videos.[videoId].timelines.update',
                    ['videoId' => $video->getId()],
                    $permissions
                );
                return;
            }

            // Wipe previous thumbnail sheets so a source-file update can regenerate
            // cleanly against the UNIQUE (videoId, name) index. Leave timeline.vtt
            // for an in-place size update in persistTimelineVtt().
            $existing = $dbForProject->find('videosTimelines', [
                Query::equal('videoInternalId', [$video->getSequence()]),
                Query::notEqual('name', ['timeline.vtt']),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($existing as $sheetRow) {
                $path = $sheetRow->getAttribute('path', '');
                if (!empty($path) && $deviceForVideos->exists($path)) {
                    try {
                        $deviceForVideos->delete($path);
                    } catch (\Throwable) {
                        // Best-effort; the DB row is the source of truth for readiness.
                    }
                }
                $dbForProject->deleteDocument('videosTimelines', $sheetRow->getId());
            }

            // Appwrite rewrites sheet URLs to thumbnail endpoints, so skip the
            // library-written VTT and render our own from cues().
            $stage = 'tile';
            $phaseStart = \microtime(true);
            try {
                $sheet = $encoder->tile(
                    $inPath,
                    \rtrim($workspace['outDir'], '/'),
                    // 480px stays sharp on HiDPI screens and enlarged scrub frames; a 4x4
                    // grid keeps each sheet at ~1920x1080, small enough for hover scrubbing.
                    (new Tile())->width(480)->grid(4, 4)->quality(2)->vtt(false)
                );
            } catch (\Throwable $th) {
                Span::add('timings.tile', \round(\microtime(true) - $phaseStart, 3));
                throw $th;
            }

            $this->phase($telemetry, VideoAction::Timeline->value, 'tile', $phaseStart);
            Span::add('timeline.sheets', \count($sheet->images()));

            $stage = 'upload';
            $phaseStart = \microtime(true);
            $size = 0;
            $timelineDir = $deviceForVideos->getPath($video->getId()) . '/timeline/';
            $urls = [];

            foreach ($sheet->images() as $localFile) {
                $fileName = \basename($localFile);
                $fullPath = $timelineDir . $fileName;
                $data = (new Local('/'))->read($localFile);
                // Retained bytes. StatsResources sums this column into the videos.storage gauge.
                $bytes = $data->getSize() ?? (\filesize($localFile) ?: 0);
                $size += $bytes;

                $thumbnail = $dbForProject->createDocument('videosTimelines', new Document([
                    'videoId' => $video->getId(),
                    'videoInternalId' => $video->getSequence(),
                    'name' => $fileName,
                    'path' => $fullPath,
                    'size' => $bytes,
                ]));

                $deviceForVideos->write(
                    $fullPath,
                    $data,
                    'image/jpeg'
                );

                // Relative to /v1/videos/{videoId}/timelines so the player resolves
                // to /v1/videos/{videoId}/thumbnails/{thumbnailId}.
                $urls[$fileName] = 'thumbnails/' . $thumbnail->getId();
            }

            if (!empty($urls)) {
                $vtt = $sheet->render(fn (string $file): string => $urls[$file] ?? $file);
                $vttPath = $deviceForVideos->getPath($video->getId() . '/timeline') . '/timeline.vtt';
                // Retained bytes. StatsResources sums this column into the videos.storage gauge.
                $bytes = \strlen($vtt);
                $size += $bytes;
                $deviceForVideos->write($vttPath, new Stream($vtt), 'text/vtt');
                $this->persistTimelineVtt($dbForProject, $video, $vttPath, $bytes);
            }

            $this->phase($telemetry, VideoAction::Timeline->value, 'upload', $phaseStart);
            Span::add('timeline.size', $size);

            $parked = $this->timelineStatus($dbForProject, $video, Base::STATUS_READY, null, $claimUpdatedAt);
            if ($parked->isEmpty()) {
                $outcome = 'reclaimed';
                return;
            }
            $this->notify(
                $queueForRealtime,
                $project,
                $parked,
                'videos.[videoId].timelines.update',
                ['videoId' => $video->getId()],
                $permissions
            );
            $outcome = 'ready';
        } catch (\Throwable $th) {
            Span::add('error.stage', $stage);
            // A rejected source carries the ffprobe run that rejected it.
            $runtime = $th instanceof Runtime ? $th : $th->getPrevious();
            if ($runtime instanceof Runtime) {
                $this->runtime($telemetry, $runtime, 'ffmpeg', $stage);
            }

            // Only a run that still owns the claim may park as error.
            if ($claimUpdatedAt === '') {
                if ($th instanceof AppwriteException && $th->getCode() < 500) {
                    Span::add('error.type', $th->getType());
                    Span::add('warning.message', $th->getMessage());
                    return;
                }
                throw $th;
            }

            $reason = $this->reason($th, $stage);
            Span::add('error.reason', $reason);
            try {
                $parked = $this->timelineStatus($dbForProject, $video, Base::STATUS_ERROR, $reason, $claimUpdatedAt);
            } catch (\Throwable $park) {
                // A bookkeeping failure must not replace the job failure.
                $this->log('Videos worker: failed to park timeline for video ' . $video->getId() . ': ' . $park->getMessage(), 'error');
                Span::add('error.park_failed', true);
                $parked = new Document([]);
            }
            if ($parked->isEmpty()) {
                $outcome = 'reclaimed';
                if ($th instanceof AppwriteException && $th->getCode() < 500) {
                    Span::add('error.type', $th->getType());
                    Span::add('warning.message', $th->getMessage());
                    return;
                }
                throw $th;
            }
            $this->notify(
                $queueForRealtime,
                $project,
                $parked,
                'videos.[videoId].timelines.update',
                ['videoId' => $video->getId()],
                $permissions
            );
            if ($th instanceof AppwriteException && $th->getCode() < 500) {
                Span::add('error.type', $th->getType());
                Span::add('warning.message', $th->getMessage());
                return;
            }
            throw $th;
        } finally {
            Span::add('timeline.outcome', $outcome);
            $this->outcome(
                $telemetry,
                VideoAction::Timeline->value,
                $outcome,
                $started,
                $outcome === 'error' ? ['error_stage' => $stage] : []
            );
            if ($workspace !== null) {
                $this->cleanup($workspace['basePath']);
            }
        }
    }

    /**
     * Normalise a caption to WebVTT, write a segment row and upload the file.
     */
    private function caption(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Document $project,
        VideoMessage $videoMessage,
        Telemetry $telemetry
    ): void {
        $caption = $videoMessage->caption;

        if ($caption === null || $caption->isEmpty()) {
            throw new \Exception('Missing caption in payload');
        }

        // Re-fetch rather than trust the queue snapshot: a caption created before
        // the source was probed carries duration 0, which would bake
        // targetDuration "0.0" into the caption playlist.
        $video = $dbForProject->getDocument('videos', $videoMessage->video->getId());
        if ($video->isEmpty()) {
            $video = $videoMessage->video;
        }
        // Created only after this run wins the pending→started claim, so a lost
        // race never mkdir's a scratch directory.
        $workspace = null;
        $permissions = $this->readPermissions($video);
        Span::add('caption.code', (string) $caption->getAttribute('code', ''));
        Span::add('caption.default', (bool) $caption->getAttribute('default', false));
        Span::add('caption.bucket.id', (string) $caption->getAttribute('bucketId', ''));
        Span::add('caption.file.id', (string) $caption->getAttribute('fileId', ''));
        $started = \microtime(true);
        $stage = 'claim';
        $outcome = 'error';
        $conversion = '';
        $claimed = false;
        // Ownership token: claim and reclaim bump $updatedAt; ready and park
        // writes require it to still match.
        $claimUpdatedAt = '';

        try {
            $current = $dbForProject->getDocument('videosCaptions', $caption->getId());
            if ($current->isEmpty()) {
                $outcome = 'duplicate';
                return;
            }

            $rowStatus = (string) $current->getAttribute('status', '');
            if ($rowStatus === Base::STATUS_PENDING) {
                // Compare-and-swap: only one updateDocuments may leave pending.
                // A delete that wins while still pending removes the row, this
                // updates nothing, and the job stops before download.
                $updated = $dbForProject->updateDocuments(
                    'videosCaptions',
                    new Document([
                        'status' => Base::STATUS_STARTED,
                        'error' => null,
                    ]),
                    [
                        Query::equal('$id', [$caption->getId()]),
                        Query::equal('status', [Base::STATUS_PENDING]),
                    ]
                );
                if ($updated === 0) {
                    $outcome = 'duplicate';
                    return;
                }
            } elseif ($rowStatus === Base::STATUS_STARTED) {
                $priorUpdatedAt = (string) $current->getUpdatedAt();
                $updated = $dbForProject->updateDocuments(
                    'videosCaptions',
                    new Document([
                        'status' => Base::STATUS_STARTED,
                        'error' => null,
                        'path' => null,
                        'size' => 0,
                        'targetDuration' => null,
                    ]),
                    [
                        Query::equal('$id', [$caption->getId()]),
                        Query::equal('status', [Base::STATUS_STARTED]),
                        Query::equal('$updatedAt', [$priorUpdatedAt]),
                    ]
                );
                if ($updated === 0) {
                    $outcome = 'duplicate';
                    return;
                }

                $this->wipeCaptionTrace($dbForProject, $deviceForVideos, $current);
                $this->log('Caption ' . $caption->getId() . ' reclaimed from started');
            } else {
                $outcome = 'duplicate';
                return;
            }

            $caption = $dbForProject->getDocument('videosCaptions', $caption->getId());
            $claimed = true;
            $claimUpdatedAt = (string) $caption->getUpdatedAt();
            $this->notify(
                $queueForRealtime,
                $project,
                $caption,
                'videos.[videoId].captions.[captionId].update',
                [
                    'videoId' => $caption->getAttribute('videoId', $video->getId()),
                    'captionId' => $caption->getId(),
                ],
                $permissions
            );

            $workspace = $this->jobWorkspace(
                $videoMessage->project->getId(),
                $video->getId(),
                Base::JOB_CAPTION,
                \uniqid('', true)
            );

            $stage = 'download';
            $phaseStart = \microtime(true);
            $file = $this->resolveFile($dbForProject, $caption);
            Span::add('caption.source_name', (string) $file->getAttribute('name', ''));
            Span::add('caption.source_path', (string) $file->getAttribute('path', ''));
            Span::add('caption.source_mime', (string) $file->getAttribute('mimeType', ''));
            Span::add('caption.source_size', (int) $file->getAttribute('sizeOriginal', 0));
            $downloaded = $this->download($deviceForFiles, $file, $workspace['inDir']);
            $ext = \strtolower(\pathinfo($downloaded, PATHINFO_EXTENSION));
            Span::add('caption.source_ext', $ext);
            $this->phase($telemetry, VideoAction::Caption->value, 'download', $phaseStart);
            $captionPath = $workspace['inDir'] . $caption->getId() . '.vtt';

            $stage = 'convert';
            $phaseStart = \microtime(true);
            if ($ext === 'srt') {
                $conversion = 'srt';
                $this->subripToWebvtt($downloaded, $captionPath);
            } elseif (\in_array($ext, ['vtt', 'webvtt'], true)) {
                $conversion = 'copy';
                if (!\copy($downloaded, $captionPath)) {
                    throw new \Exception('Failed to stage WebVTT caption');
                }
            } else {
                // text/plain and application/x-subrip without a .srt extension: try
                // SubRip. A parse failure fails the job — never persist non-WebVTT
                // bytes under a .vtt path.
                $conversion = 'srt';
                $this->subripToWebvtt($downloaded, $captionPath);
            }
            $this->phase($telemetry, VideoAction::Caption->value, 'convert', $phaseStart);
            Span::add('caption.conversion', $conversion);

            $stage = 'persist';
            $phaseStart = \microtime(true);
            // Maintenance or a reclaim may have moved the row out of this run.
            $current = $dbForProject->getDocument('videosCaptions', $caption->getId());
            if (
                $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
                || (string) $current->getUpdatedAt() !== $claimUpdatedAt
            ) {
                $outcome = (
                    !$current->isEmpty()
                    && (string) $current->getUpdatedAt() !== $claimUpdatedAt
                ) ? 'reclaimed' : 'halted';
                $caption = $current;
                return;
            }

            $caption = $this->persistCaptionVtt(
                $dbForProject,
                $deviceForVideos,
                $video,
                $caption,
                $captionPath,
                $queueForRealtime,
                $project,
                $permissions,
                $claimUpdatedAt
            );
            if ($caption->isEmpty()) {
                $outcome = 'reclaimed';
                return;
            }
            $this->phase($telemetry, VideoAction::Caption->value, 'persist', $phaseStart);
            Span::add('caption.size', (int) $caption->getAttribute('size', 0));
            $outcome = 'ready';
        } catch (\Throwable $th) {
            Span::add('error.stage', $stage);
            // Only a run that still owns the claim may park it as error — a lost
            // claim must not overwrite a live job or resurrect a deleted caption.
            // Do not overwrite aborted/error parks from maintenance.
            $current = $dbForProject->getDocument('videosCaptions', $caption->getId());
            $status = $current->isEmpty() ? '' : (string) $current->getAttribute('status', '');
            $owns = $claimed
                && (string) $current->getUpdatedAt() === $claimUpdatedAt;
            $mayPark = $owns
                ? !\in_array($status, [Base::STATUS_ABORTED, Base::STATUS_ERROR], true)
                : (!$claimed && $status === Base::STATUS_PENDING);

            if (!$current->isEmpty() && $mayPark) {
                $reason = $this->reason($th, $stage);
                Span::add('error.reason', $reason);
                try {
                    $queries = [
                        Query::equal('$id', [$caption->getId()]),
                    ];
                    if ($owns) {
                        $queries[] = Query::equal('$updatedAt', [$claimUpdatedAt]);
                        $queries[] = Query::equal('status', [Base::STATUS_STARTED]);
                    } else {
                        $queries[] = Query::equal('status', [Base::STATUS_PENDING]);
                    }
                    $parked = $dbForProject->updateDocuments(
                        'videosCaptions',
                        new Document([
                            'status' => Base::STATUS_ERROR,
                            'error' => $reason,
                        ]),
                        $queries
                    );
                    if ($parked > 0) {
                        $caption = $dbForProject->getDocument('videosCaptions', $caption->getId());
                        $this->notify(
                            $queueForRealtime,
                            $project,
                            $caption,
                            'videos.[videoId].captions.[captionId].update',
                            [
                                'videoId' => $caption->getAttribute('videoId', $video->getId()),
                                'captionId' => $caption->getId(),
                            ],
                            $permissions
                        );
                    }
                } catch (\Throwable $park) {
                    // A bookkeeping failure must not replace the job failure.
                    $this->log('Videos worker: failed to park caption ' . $caption->getId() . ': ' . $park->getMessage(), 'error');
                    Span::add('error.park_failed', true);
                }
            }

            // The owner's problem: recorded as a warning, not raised to the harness.
            if ($th instanceof AppwriteException && $th->getCode() < 500) {
                Span::add('error.type', $th->getType());
                Span::add('warning.message', $th->getMessage());
                return;
            }
            throw $th;
        } finally {
            Span::add('caption.outcome', $outcome);
            Span::add('caption.status', (string) $caption->getAttribute('status', ''));
            $this->outcome(
                $telemetry,
                VideoAction::Caption->value,
                $outcome,
                $started,
                $outcome === 'error' ? ['error_stage' => $stage] : []
            );
            if ($workspace !== null) {
                $this->cleanup($workspace['basePath']);
            }
        }
    }

    /**
     * Transcode and package a rendition into HLS or DASH.
     *
     * The rendition row is created by the HTTP endpoint with status `pending`.
     */
    private function encode(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Context $usage,
        UsagePublisher $publisherForUsage,
        Document $project,
        VideoMessage $videoMessage,
        Telemetry $telemetry
    ): void {
        $rendition = $videoMessage->rendition;
        $profile = $videoMessage->profile;

        if ($rendition === null || $rendition->isEmpty()) {
            throw new \Exception('Missing rendition in payload');
        }

        if ($profile === null || $profile->isEmpty()) {
            throw new \Exception('Missing profile in payload');
        }

        $projectId = $videoMessage->project->getId();
        $videoId = $videoMessage->video->getId();
        // Created only after this run wins the pending→started claim. Workspace
        // paths are keyed by rendition id, so a stale redelivery that mkdir+rm's
        // the same tree would delete segments out from under a live ffmpeg.
        $workspace = null;
        // Started once the encode is set up; finished in the upload stage and
        // abandoned in finally, before the rendition path is removed.
        $uploader = null;
        $startedAt = \microtime(true);
        $storageBytes = 0;
        // Set once this run has handed a segment to the uploader, so a run that
        // does not reach `ready` removes what went up (finally).
        $path = null;
        $wrote = false;
        $output = $videoMessage->output !== ''
            ? $videoMessage->output
            : (string) $rendition->getAttribute('output', Base::OUTPUT_HLS);
        $permissions = $this->readPermissions($videoMessage->video);
        $claimed = false;
        // Ownership token for this run. Progress, persist, ready, park, usage and
        // partial cleanup all require the row's startedAt to still match.
        $claimStartedAt = '';
        $codec = Base::normalizeCodec(
            $rendition->getAttribute('codec')
                ?: $profile->getAttribute('codec')
        );
        $stage = 'claim';
        $outcome = 'error';
        $status = '';
        $last = null;
        // Set by the progress listener when the row leaves `started` (sweeper
        // abort, error park, e2e seed); it then stops ffmpeg and this pack never
        // resumes DB writes, even if status is set back to `started`.
        $halted = false;
        // Every log line of this run names the job: the span is written only
        // when the job ends, so these lines are all a killed worker leaves.
        $tag = 'Rendition ' . $rendition->getId() . ' (project ' . $projectId . ', video ' . $videoId . ')';

        try {
            // pending: first claim. started/ended: a killed worker left the row
            // mid-run — reclaim, wipe its trace, and encode again. ready/error/
            // aborted: settled; a redelivery is a no-op (no second usage event).
            $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            if ($current->isEmpty()) {
                $outcome = 'duplicate';
                return;
            }

            $rowStatus = (string) $current->getAttribute('status', '');
            if ($rowStatus === Base::STATUS_PENDING) {
                // Compare-and-swap: concurrent coroutines can both see `pending`;
                // only one updateDocuments may transition the row. Claim before
                // downloading so a duplicate redelivery never fetches the source.
                $updated = $dbForProject->updateDocuments(
                    'videosRenditions',
                    new Document([
                        'startedAt' => DateTime::now(),
                        'status' => Base::STATUS_STARTED,
                        'progress' => '0',
                    ]),
                    [
                        Query::equal('$id', [$rendition->getId()]),
                        Query::equal('status', [Base::STATUS_PENDING]),
                    ]
                );
                if ($updated === 0) {
                    $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                    $outcome = 'duplicate';
                    $status = (string) $current->getAttribute('status', Base::STATUS_STARTED);
                    return;
                }
            } elseif (\in_array($rowStatus, [Base::STATUS_STARTED, Base::STATUS_ENDED], true)) {
                $priorStartedAt = (string) $current->getAttribute('startedAt', '');
                $queries = [
                    Query::equal('$id', [$rendition->getId()]),
                    Query::equal('status', [$rowStatus]),
                    Query::equal('startedAt', [$priorStartedAt]),
                ];
                if ($rowStatus === Base::STATUS_ENDED) {
                    $queries[] = Query::equal('endedAt', [(string) $current->getAttribute('endedAt', '')]);
                }

                $updated = $dbForProject->updateDocuments(
                    'videosRenditions',
                    new Document([
                        'startedAt' => DateTime::now(),
                        'status' => Base::STATUS_STARTED,
                        'progress' => '0',
                        'size' => 0,
                        'endedAt' => null,
                        'metadata' => null,
                        'targetDuration' => null,
                        'error' => null,
                    ]),
                    $queries
                );
                if ($updated === 0) {
                    $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                    $outcome = 'duplicate';
                    $status = (string) $current->getAttribute('status', '');
                    return;
                }

                $this->wipeRenditionTrace($dbForProject, $deviceForVideos, $current);
                $this->log($tag . ' reclaimed from ' . $rowStatus);
            } else {
                $outcome = 'duplicate';
                $status = $rowStatus;
                return;
            }

            $rendition = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            $claimed = true;
            $claimStartedAt = (string) $rendition->getAttribute('startedAt', '');
            Span::add('rendition.name', (string) $rendition->getAttribute('name', ''));
            Span::add('rendition.output', $output);
            Span::add('rendition.codec', $codec);
            $this->log(
                $tag . ' started as ' . $rendition->getAttribute('name', '')
                . ' (' . $output . ', ' . $codec . ')'
            );
            $stage = 'download';
            $this->log($tag . ' download start');
            $this->notify(
                $queueForRealtime,
                $project,
                $rendition,
                'videos.[videoId].renditions.[renditionId].update',
                [
                    'videoId' => $rendition->getAttribute('videoId', ''),
                    'renditionId' => $rendition->getId(),
                ],
                $permissions
            );

            $workspace = $this->jobWorkspace(
                $projectId,
                $videoId,
                Base::JOB_RENDITION,
                $rendition->getId()
            );
            [$video, $inPath, $headers] = $this->prepareSource(
                $dbForProject,
                $deviceForFiles,
                $deviceForVideos,
                $dbForProject->getDocument('videos', $videoId),
                $workspace,
                $queueForRealtime,
                $project,
                $permissions,
                $telemetry,
                VideoAction::Encode->value,
                $stage
            );
            $this->log($tag . ' download done');

            // Silent reporters: failures reach the span through the exception,
            // so the library must not print its own copy to stdout.
            $probe = new FFprobe(protocols: Base::SOURCE_PROTOCOLS, formats: Base::SOURCE_FORMATS, options: Base::SOURCE_OPTIONS, headers: $headers);
            $probe->setReporter(new Silent());
            $timeout = $this->timeout(
                $video,
                (self::ENCODE_FACTORS[$codec] ?? self::ENCODE_FACTORS[Base::CODEC_H264])
                * ((int) $profile->getAttribute('height') > 1080 ? 2 : 1)
            );
            Span::add('encode.timeout', $timeout);
            $threads = $this->threads();
            $packager = new Packager(
                new FFmpeg(timeout: $timeout, threads: $threads, protocols: Base::SOURCE_PROTOCOLS, formats: Base::SOURCE_FORMATS, options: Base::SOURCE_OPTIONS, headers: $headers),
                probe: $probe,
                reporter: new Silent()
            );

            $stage = 'probe';
            $this->log($tag . ' probe start');
            if (!$packager->valid($inPath)) {
                throw new AppwriteException(AppwriteException::VIDEO_NOT_VALID);
            }
            $this->log($tag . ' probe done');
            $stage = 'encode';

            $representation = new Representation(
                width: (int) $profile->getAttribute('width'),
                height: (int) $profile->getAttribute('height'),
                video: (int) $profile->getAttribute('videoBitRate'),
                audio: \max(1, (int) $profile->getAttribute('audioBitRate')),
            );
            Span::add('rendition.width', (int) $profile->getAttribute('width'));
            Span::add('rendition.height', (int) $profile->getAttribute('height'));
            Span::add('rendition.video_bitrate', (int) $profile->getAttribute('videoBitRate'));
            Span::add('rendition.audio_bitrate', (int) $profile->getAttribute('audioBitRate'));
            Span::add('encoder.name', $packager->getName());
            Span::add('encoder.threads', $threads);

            $this->log($tag . ' conversion start');
            $this->log($tag . ' encoding (timeout ' . $timeout . 's, ' . $threads . ' threads)');

            // Apply loudnorm only when the probe found an audio stream.
            // On inputs under 3s loudnorm falls back to a linear gain from the
            // integrated loudness, which is -inf for digital silence, so it
            // emits NaN samples that the AAC encoder rejects. The aeval stage
            // maps those back to silence.
            $params = ['-dn', '-sn'];
            if (\trim((string) $video->getAttribute('audioCodec', '')) !== '') {
                $params[] = '-af';
                $params[] = 'loudnorm=I=-14:TP=-1.5:LRA=11,aeval=if(isnan(val(ch))+isinf(val(ch))\,0\,val(ch)):c=same';
            }

            $format = match ($codec) {
                Base::CODEC_HEVC => (new HEVC())
                    ->crf(22)
                    ->keyframe(2.0)
                    ->params($params),
                Base::CODEC_VP9 => (new VP9())
                    ->crf(32)
                    ->keyframe(2.0)
                    ->params($params),
                default => (new X264())
                    ->crf(22)
                    ->bframes(3)
                    ->keyframe(2.0)
                    ->params($params),
            };

            // FFmpeg writes each segment's duration only into a SegmentTimeline,
            // and only when templates are on. Playback rebuilds the MPD from
            // those durations. One SegmentList duration repeats the longest
            // segment, so a long video seeks into the wrong segment and never
            // requests the tail.
            $target = match ($output) {
                Base::OUTPUT_DASH => (new Dash())->template(true)->timeline(true)->segment(self::SEGMENT_DURATION)->manifests(false),
                Base::OUTPUT_CMAF => (new Cmaf())->template(true)->timeline(true)->segment(self::SEGMENT_DURATION)->manifests(false),
                default => (new Hls())->segment(self::SEGMENT_DURATION)->manifests(false),
            };

            $path = $deviceForVideos->getPath($video->getId())
                . '/' . $rendition->getAttribute('name')
                . '-' . $rendition->getId() . '/';
            // Persist before the first segment write so a kill mid-upload still
            // leaves a path the delete worker can remove.
            $rendition = $dbForProject->updateDocument(
                'videosRenditions',
                $rendition->getId(),
                new Document([
                    'path' => $path,
                ])
            );
            // Segments go up while ffmpeg runs, from a coroutine of their own:
            // the hand-over never waits, so the store's time never counts
            // against the ffmpeg timeout, and scratch holds only the segments
            // the store has not taken yet.
            $uploader = new Uploader($deviceForVideos, $path);
            $uploader->start();
            $this->log($tag . ' upload start');

            // The claim wrote '0'.
            $lastProgress = 0;
            $phaseStart = \microtime(true);
            $advancedAt = $phaseStart;
            $advancedTime = -1.0;
            try {
                $package = $packager
                    ->open($inPath)
                    ->format($format)
                    ->add($representation)
                    ->output($target)
                    // Each segment is handed to the uploader as soon as ffmpeg has
                    // finished it and leaves scratch once it is up. A failed upload
                    // is thrown here at the next segment, which stops ffmpeg; the
                    // objects already written are removed in finally.
                    ->on(Packager::SEGMENT, function (mixed $written) use ($uploader, &$wrote) {
                        if (!$written instanceof Written) {
                            return;
                        }
                        $wrote = true;
                        $uploader->take($written);
                    })
                    ->on(Packager::PROGRESS, function (mixed $progress) use ($dbForProject, $queueForRealtime, $project, $permissions, &$rendition, &$lastProgress, &$halted, &$last, &$advancedAt, &$advancedTime, $claimStartedAt) {
                        if (!$progress instanceof Progress) {
                            return;
                        }
                        $last = $progress;
                        if ($halted) {
                            return;
                        }

                        // Throwing from a listener makes the library kill ffmpeg.
                        $now = \microtime(true);
                        if ($progress->time > $advancedTime) {
                            $advancedTime = $progress->time;
                            $advancedAt = $now;
                        } elseif (($now - $advancedAt) > self::STALL_TIMEOUT) {
                            Span::add('ffmpeg.stalled', true);
                            throw new Runtime('Command "ffmpeg" timed out after ' . self::STALL_TIMEOUT . 's without progress');
                        }

                        $percentage = (int) \round($progress->percent);
                        // Status is read only on a 3% step, right before the progress
                        // write. Deletes are gated while a rendition runs, so only the
                        // maintenance sweep (or an e2e park) moves the row out of
                        // `started`, and noticing that a step later is soon enough.
                        // A step is 3 points since the last write, not a multiple of 3:
                        // ffmpeg reports about twice a second, so a fast encode jumps
                        // several points per report and can skip every multiple.
                        if ($percentage - $lastProgress < 3) {
                            return;
                        }
                        $lastProgress = $percentage;

                        $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                        if (
                            $current->isEmpty()
                            || $current->getAttribute('status') !== Base::STATUS_STARTED
                            || (string) $current->getAttribute('startedAt', '') !== $claimStartedAt
                        ) {
                            // Stop ffmpeg now instead of letting it finish unused work.
                            $halted = true;
                            throw new \Exception('Encode halted: rendition left started');
                        }

                        $rendition = $dbForProject->updateDocument(
                            'videosRenditions',
                            $rendition->getId(),
                            new Document([
                                'progress' => (string) $percentage,
                            ])
                        );
                        $this->notify(
                            $queueForRealtime,
                            $project,
                            $rendition,
                            'videos.[videoId].renditions.[renditionId].update',
                            [
                                'videoId' => $rendition->getAttribute('videoId', ''),
                                'renditionId' => $rendition->getId(),
                            ],
                            $permissions
                        );
                    })
                    ->pack(\rtrim($workspace['outDir'], '/'));
                // Closed here so the phase measures ffmpeg, not the uploads it left behind.
                $this->phase($telemetry, VideoAction::Encode->value, 'encode', $phaseStart);
            } catch (\Throwable $th) {
                // phase() only closes a completed phase, but how long ffmpeg ran
                // is the first question on a timeout, a stall or an abort.
                Span::add('timings.encode', \round(\microtime(true) - $phaseStart, 3));
                throw $th;
            } finally {
                // ffmpeg is done or gone: nothing more will be handed over. The
                // uploader finishes what is queued and is waited for in the
                // upload stage, or in the outer finally, before this run touches
                // scratch or the rendition path again.
                $uploader->close();
                // Segments the store had not taken when ffmpeg exited: its lag.
                Span::add('encode.backlog', $uploader->pending());
            }
            // pack() was the last reader; free a copied or decoded source before
            // persist/upload. A source read in place or over a URL is not ours.
            if (\str_starts_with($inPath, $workspace['inDir']) && \is_file($inPath) && !@\unlink($inPath)) {
                $this->log('Videos worker: failed to remove source after encode: ' . $inPath, 'warning');
                Span::add('source.cleanup_failed', true);
            }
            if ($last !== null && $last->speed > 0) {
                $this->record(
                    $telemetry->createHistogram(
                        'worker.videos.encode.speed',
                        '1',
                        'Encode speed as a multiple of realtime',
                        ['ExplicitBucketBoundaries' => self::SPEED_BUCKETS]
                    ),
                    $last->speed,
                    ['codec' => $codec, 'output' => $output]
                );
            }

            // Maintenance, an e2e park, or a reclaim may have moved the row out
            // of this run's ownership while ffmpeg was still running.
            $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            if (
                $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
                || (string) $current->getAttribute('startedAt', '') !== $claimStartedAt
            ) {
                $outcome = $this->encodeLost($current, $claimStartedAt);
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            $stage = 'persist';
            $phaseStart = \microtime(true);
            // Drop any leftover segments from a previous attempt at the same id.
            // deleteDocuments paginates internally, so a long rendition's >1000
            // segment rows (a ~100-minute HLS ladder at 4s segments) are all
            // removed, not just the first APP_LIMIT_SUBQUERY page.
            $dbForProject->deleteDocuments('videosRenditionsSegments', [
                Query::equal('renditionInternalId', [$rendition->getSequence()]),
            ]);

            [$metadata, $targetDuration] = $this->persistPackage(
                $dbForProject,
                $package,
                $rendition,
                $path,
                $output
            );
            $this->phase($telemetry, VideoAction::Encode->value, 'persist', $phaseStart);
            Span::add('rendition.segments', \count($package->segments()));
            Span::add('rendition.files', \count($package->files()));

            $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            if (
                $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
                || (string) $current->getAttribute('startedAt', '') !== $claimStartedAt
            ) {
                $outcome = $this->encodeLost($current, $claimStartedAt);
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            $updated = $dbForProject->updateDocuments(
                'videosRenditions',
                new Document(\array_filter([
                    'status' => Base::STATUS_ENDED,
                    'endedAt' => DateTime::now(),
                    'metadata' => $metadata,
                    'targetDuration' => $targetDuration,
                ], fn ($value) => $value !== null)),
                [
                    Query::equal('$id', [$rendition->getId()]),
                    Query::equal('status', [Base::STATUS_STARTED]),
                    Query::equal('startedAt', [$claimStartedAt]),
                ]
            );
            if ($updated === 0) {
                $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                $outcome = $this->encodeLost($current, $claimStartedAt);
                $status = (string) $current->getAttribute('status', '');
                return;
            }
            $rendition = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            $this->notify(
                $queueForRealtime,
                $project,
                $rendition,
                'videos.[videoId].renditions.[renditionId].update',
                [
                    'videoId' => $rendition->getAttribute('videoId', ''),
                    'renditionId' => $rendition->getId(),
                ],
                $permissions
            );

            $this->log($tag . ' conversion done');

            $stage = 'upload';
            $phaseStart = \microtime(true);
            // Upload already ran for the whole encode. Every segment went through
            // the uploader, init and last ones included: the final scan inside
            // pack() reports them all, and no output writes manifests. This only
            // waits for the store to take what was still queued when ffmpeg exited.
            $storageBytes = $uploader->finish();
            $this->phase($telemetry, VideoAction::Encode->value, 'upload', $phaseStart);
            Span::add('rendition.size', $storageBytes);
            $this->log($tag . ' upload done');

            $stage = 'finalize';
            $this->log($tag . ' finalize start');
            $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            if (
                $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_ENDED
                || (string) $current->getAttribute('startedAt', '') !== $claimStartedAt
            ) {
                $outcome = $this->encodeLost($current, $claimStartedAt);
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            $updated = $dbForProject->updateDocuments(
                'videosRenditions',
                new Document([
                    'status' => Base::STATUS_READY,
                    'progress' => '100',
                    // Retained bytes. The usage event is skipped; StatsResources
                    // sums this column into the videos.storage gauge.
                    'size' => $storageBytes,
                ]),
                [
                    Query::equal('$id', [$rendition->getId()]),
                    Query::equal('status', [Base::STATUS_ENDED]),
                    Query::equal('startedAt', [$claimStartedAt]),
                ]
            );
            if ($updated === 0) {
                $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                $outcome = $this->encodeLost($current, $claimStartedAt);
                $status = (string) $current->getAttribute('status', '');
                return;
            }
            $rendition = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            $this->notify(
                $queueForRealtime,
                $project,
                $rendition,
                'videos.[videoId].renditions.[renditionId].update',
                [
                    'videoId' => $rendition->getAttribute('videoId', ''),
                    'renditionId' => $rendition->getId(),
                ],
                $permissions
            );
            $outcome = 'ready';
            $status = Base::STATUS_READY;
            $this->log($tag . ' ready');
        } catch (\Throwable $th) {
            $current = $dbForProject->getDocument('videosRenditions', $rendition->getId());
            // The progress listener stopped ffmpeg because the row left `started`
            // or another delivery reclaimed this run's startedAt.
            if ($halted) {
                $outcome = $this->encodeLost($current, $claimStartedAt);
                $status = $current->isEmpty() ? '' : (string) $current->getAttribute('status', '');
                return;
            }

            // Do not overwrite aborted/error parks from maintenance or e2e seeding.
            // Pre-claim failures may only park while the row is still `pending` —
            // never clobber a sibling coroutine that already won the claim.
            // A reclaim that took this run's startedAt also must not be parked.
            $status = $current->isEmpty() ? '' : (string) $current->getAttribute('status', '');
            $owns = $claimed
                && (string) $current->getAttribute('startedAt', '') === $claimStartedAt;
            $mayPark = $owns
                ? !\in_array($status, [Base::STATUS_ABORTED, Base::STATUS_ERROR], true)
                : (!$claimed && $status === Base::STATUS_PENDING);

            if (!$current->isEmpty() && $mayPark) {
                $reason = $this->reason($th, $stage);
                Span::add('error.reason', $reason);
                try {
                    $queries = [
                        Query::equal('$id', [$rendition->getId()]),
                    ];
                    if ($owns) {
                        $queries[] = Query::equal('startedAt', [$claimStartedAt]);
                        $queries[] = Query::equal('status', [
                            Base::STATUS_STARTED,
                            Base::STATUS_ENDED,
                        ]);
                    } else {
                        $queries[] = Query::equal('status', [Base::STATUS_PENDING]);
                    }
                    $parked = $dbForProject->updateDocuments(
                        'videosRenditions',
                        new Document([
                            'status' => Base::STATUS_ERROR,
                            'endedAt' => DateTime::now(),
                            'progress' => $rendition->getAttribute('progress', '0'),
                            'error' => $reason,
                        ]),
                        $queries
                    );
                    if ($parked > 0) {
                        $rendition = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                        $this->notify(
                            $queueForRealtime,
                            $project,
                            $rendition,
                            'videos.[videoId].renditions.[renditionId].update',
                            [
                                'videoId' => $rendition->getAttribute('videoId', ''),
                                'renditionId' => $rendition->getId(),
                            ],
                            $permissions
                        );
                        $status = Base::STATUS_ERROR;
                    }
                } catch (\Throwable $park) {
                    // A bookkeeping failure must not replace the job failure.
                    $this->log('Videos worker: failed to park rendition ' . $rendition->getId() . ': ' . $park->getMessage(), 'error');
                    Span::add('error.park_failed', true);
                }
            }

            // The harness finishes the span with this exception, so the exporter
            // adds error.type/message/file/line; only the stage and ffmpeg detail
            // are added here.
            Span::add('error.stage', $stage);
            // A rejected source carries the ffprobe run that rejected it.
            $runtime = $th instanceof Runtime ? $th : $th->getPrevious();
            if ($runtime instanceof Runtime) {
                $this->runtime($telemetry, $runtime, 'ffmpeg', $stage);
            }

            // The owner's problem: recorded as a warning, not raised to the harness.
            if ($th instanceof AppwriteException && $th->getCode() < 500) {
                Span::add('error.type', $th->getType());
                Span::add('warning.message', $th->getMessage());
                return;
            }
            throw $th;
        } finally {
            // Waited for on every path before the rendition path is removed: a
            // write still in flight after the delete would leave an object
            // behind, and the workspace cleanup would pull its file away.
            $uploader?->abandon();
            // On every path: a failed or halted run is where what reached the
            // store matters. A failed write is thrown from the segment listener,
            // so without the flag it reads as an encode failure.
            if ($uploader !== null) {
                Span::add('upload.chunks', $uploader->chunks());
                Span::add('upload.speed', $uploader->speed());
                Span::add('upload.failed', $uploader->failed());
            }

            Span::add('rendition.outcome', $outcome);
            Span::add('rendition.status', $status);
            Span::add('rendition.claimed', $claimed);
            if ($outcome === 'halted') {
                Span::add('rendition.halt_status', $status);
                Span::add('rendition.halt_stage', $stage);
            }
            if ($last !== null) {
                Span::add('encode.progress', \round($last->percent, 2));
                Span::add('encode.speed', $last->speed);
                Span::add('encode.fps', $last->fps);
            }
            $this->outcome(
                $telemetry,
                VideoAction::Encode->value,
                $outcome,
                $startedAt,
                ['output' => $output, 'codec' => $codec] + ($outcome === 'error' ? ['error_stage' => $stage] : [])
            );

            // Only a run that still owns the claim is billable. A duplicate, or a
            // loser whose startedAt was taken by a reclaim, must not publish.
            $owns = false;
            if ($claimed && $claimStartedAt !== '') {
                $owned = $dbForProject->getDocument('videosRenditions', $rendition->getId());
                $owns = !$owned->isEmpty()
                    && (string) $owned->getAttribute('startedAt', '') === $claimStartedAt;
                if ($owns) {
                    $rendition = $owned;
                }
            }
            if ($owns) {
                try {
                    $computeMs = (int) \round((\microtime(true) - $startedAt) * 1000);
                    VideoUsage::publish(
                        $usage,
                        $videoMessage->video,
                        $rendition,
                        $project,
                        $publisherForUsage,
                        $storageBytes,
                        $computeMs,
                        $this->specification()
                    );
                } catch (\Throwable $th) {
                    $this->log('Failed to publish video usage for ' . $tag . ': ' . $th->getMessage(), 'error');
                    Span::add('usage.publish_failed', true);
                }
            }

            // Segments go up while ffmpeg runs, so an error, a halt or a failed
            // upload can leave part of a rendition in storage. Only this run's
            // still-owned claim leads here; a reclaim must not delete the new
            // owner's directory. Nothing serves the path until `ready`.
            if ($owns && $wrote && $path !== null && $outcome !== 'ready') {
                try {
                    $deviceForVideos->delete($path, true);
                } catch (\Throwable $th) {
                    $this->log('Videos worker: failed to remove partial rendition ' . $path . ': ' . $th->getMessage(), 'warning');
                    Span::add('rendition.cleanup_failed', true);
                }
            }

            // Never rm a workspace we did not create — that path is shared by
            // rendition id and may belong to the coroutine that claimed it.
            if ($workspace !== null) {
                $this->cleanup($workspace['basePath']);
            }
        }
    }

    /**
     * Convert a SubRip file to WebVTT on disk.
     *
     * Calls build() before save(): Captioning\File::save() does trim($fileContent)
     * while content is still null after convertTo(), which emits a PHP 8.1+
     * deprecation in vendor/captioning.
     */
    private function subripToWebvtt(string $srtPath, string $vttPath): void
    {
        try {
            $webvtt = (new SubripFile($srtPath))->convertTo('webvtt');
        } catch (\Throwable $th) {
            // The library prefixes every message with the local path.
            $detail = $th->getMessage();
            if (\str_starts_with($detail, $srtPath)) {
                $detail = \ltrim(\substr($detail, \strlen($srtPath)));
            }
            throw new AppwriteException(AppwriteException::VIDEO_CAPTION_NOT_VALID, 'Caption ' . $detail, previous: $th);
        }
        $webvtt->build();
        $webvtt->save($vttPath);
    }

    /**
     * Require a WebVTT signature before uploading as text/vtt.
     *
     * Accepts an optional UTF-8 BOM. The next character after "WEBVTT" must be
     * end-of-file, space, tab, CR, or LF (WebVTT cue-file header).
     */
    private function assertWebvtt(string $vttPath): void
    {
        $handle = \fopen($vttPath, 'rb');
        if ($handle === false) {
            throw new \Exception('Failed to read staged caption');
        }

        $prefix = \fread($handle, 16);
        \fclose($handle);

        if ($prefix === false || $prefix === '') {
            throw new AppwriteException(AppwriteException::VIDEO_CAPTION_NOT_VALID, 'Caption file is empty');
        }

        if (\str_starts_with($prefix, "\xEF\xBB\xBF")) {
            $prefix = \substr($prefix, 3);
        }

        if (!\str_starts_with($prefix, 'WEBVTT')) {
            throw new AppwriteException(AppwriteException::VIDEO_CAPTION_NOT_VALID, 'Caption file is not WebVTT');
        }

        if (\strlen($prefix) > 6) {
            $next = $prefix[6];
            if ($next !== ' ' && $next !== "\t" && $next !== "\n" && $next !== "\r") {
                throw new AppwriteException(AppwriteException::VIDEO_CAPTION_NOT_VALID, 'Caption file is not WebVTT');
            }
        }
    }

    /**
     * Record the timeline cue file so its bytes join the videos.storage gauge.
     *
     * Thumbnail sheet rows are replaced on each timeline job. This row stays and
     * its size is replaced, so a rebuild does not stack the previous cue file.
     */
    private function persistTimelineVtt(Database $dbForProject, Document $video, string $path, int $bytes): void
    {
        $existing = $dbForProject->find('videosTimelines', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('name', ['timeline.vtt']),
            Query::limit(1),
        ]);

        if (empty($existing)) {
            $dbForProject->createDocument('videosTimelines', new Document([
                'videoId' => $video->getId(),
                'videoInternalId' => $video->getSequence(),
                'name' => 'timeline.vtt',
                'path' => $path,
                'size' => $bytes,
            ]));

            return;
        }

        $dbForProject->updateDocument('videosTimelines', $existing[0]->getId(), new Document([
            'path' => $path,
            'size' => $bytes,
        ]));
    }

    /**
     * Write a staged WebVTT file as the caption's single segment and mark ready.
     *
     * When $claimUpdatedAt is set, the ready write is conditioned on that token
     * so a reclaim cannot be overwritten by a loser. An empty document means
     * another delivery took the claim.
     *
     * @param array<string> $permissions
     */
    private function persistCaptionVtt(
        Database $dbForProject,
        Device $deviceForVideos,
        Document $video,
        Document $caption,
        string $vttPath,
        Realtime $queueForRealtime,
        Document $project,
        array $permissions,
        string $claimUpdatedAt = ''
    ): Document {
        $dbForProject->deleteDocuments('videosCaptionsSegments', [
            Query::equal('captionInternalId', [$caption->getSequence()]),
        ]);

        $dir = $deviceForVideos->getPath($video->getId()) . '/captions/';
        $fileName = $caption->getId() . '.vtt';
        $fullPath = $dir . $fileName;
        // HLS EXT-X-TARGETDURATION must be a decimal-integer (seconds, rounded up).
        $duration = (string) \max(1, (int) \ceil(((int) $video->getAttribute('duration', 0)) / 1000));

        $dbForProject->createDocument('videosCaptionsSegments', new Document([
            'captionId' => $caption->getId(),
            'captionInternalId' => $caption->getSequence(),
            'fileName' => $fileName,
            'path' => $dir,
            'duration' => $duration,
        ]));

        $this->assertWebvtt($vttPath);

        $data = (new Local('/'))->read($vttPath);
        // Retained bytes. StatsResources sums this column into the videos.storage gauge.
        $bytes = $data->getSize() ?? (\filesize($vttPath) ?: 0);

        $deviceForVideos->write(
            $fullPath,
            $data,
            'text/vtt'
        );

        $ready = new Document([
            'targetDuration' => $duration,
            'status' => Base::STATUS_READY,
            'error' => null,
            'path' => $fullPath,
            'size' => $bytes,
        ]);

        if ($claimUpdatedAt === '') {
            $caption = $dbForProject->updateDocument(
                'videosCaptions',
                $caption->getId(),
                $ready
            );
        } else {
            $updated = $dbForProject->updateDocuments(
                'videosCaptions',
                $ready,
                [
                    Query::equal('$id', [$caption->getId()]),
                    Query::equal('status', [Base::STATUS_STARTED]),
                    Query::equal('$updatedAt', [$claimUpdatedAt]),
                ]
            );
            if ($updated === 0) {
                return new Document([]);
            }
            $caption = $dbForProject->getDocument('videosCaptions', $caption->getId());
        }

        $this->notify(
            $queueForRealtime,
            $project,
            $caption,
            'videos.[videoId].captions.[captionId].update',
            [
                'videoId' => $caption->getAttribute('videoId', $video->getId()),
                'captionId' => $caption->getId(),
            ],
            $permissions
        );

        return $caption;
    }

    /**
     * Register soft-text caption tracks from the source container.
     *
     * Image-based streams are skipped. An upload that already claims default
     * keeps the flag; extracted rows for the same language are still created.
     * One failed track does not fail the timeline. A probe or ffmpeg failure
     * returns false so the caller can release `captionsExtracted` and a later
     * job can retry. Tracks already stored are reused instead of inserted again.
     *
     * @param array<string> $permissions
     * @return bool True when every text track was stored or permanently skipped
     */
    private function extractEmbeddedCaptions(
        Database $dbForProject,
        Device $deviceForVideos,
        Document $video,
        string $inPath,
        string $outDir,
        Encoder $encoder,
        Realtime $queueForRealtime,
        Document $project,
        array $permissions,
        Telemetry $telemetry
    ): bool {
        try {
            $info = $encoder->probe($inPath);
        } catch (\Throwable $th) {
            Span::add('captions.error.stage', 'probe');
            Span::add('warning.message', 'Caption probe failed: ' . $th->getMessage());
            if ($th instanceof Runtime) {
                $this->runtime($telemetry, $th, 'captions.error', 'probe');
            }
            return false;
        }

        $tracks = $info->tracks(Track::SUBTITLE);
        $textStreams = 0;
        foreach ($tracks as $track) {
            if (\in_array(\strtolower((string) ($track->codec ?? '')), self::TEXT_CAPTION_CODECS, true)) {
                $textStreams++;
            }
        }
        Span::add('captions.streams', \count($tracks));
        Span::add('captions.text_streams', $textStreams);
        Span::add(
            'captions.source_tracks',
            \implode(', ', \array_map(
                static fn (Track $track) => $track->type . ':' . ($track->codec ?? 'unknown'),
                $info->tracks
            ))
        );

        $extracted = $telemetry->createCounter('worker.videos.captions.extracted', null, 'Embedded caption tracks by extraction result');
        $assignedDefault = false;
        $registered = 0;
        $skipped = 0;
        $failed = 0;
        $retry = false;
        $used = [];

        foreach ($tracks as $track) {
            $codec = \strtolower((string) ($track->codec ?? ''));

            if ($codec === '' || !\in_array($codec, self::TEXT_CAPTION_CODECS, true)) {
                $this->record($extracted, 1, ['result' => 'skipped_non_text', 'codec' => $codec !== '' ? $codec : 'unknown']);
                $skipped++;
                continue;
            }

            $code = $this->captionLanguageCode($track->language);

            $name = $this->sanitizeMeta(
                $track->title
                ?? ($track->language !== null && $track->language !== '' ? $track->language : null)
                ?? ('Track ' . $track->index)
            );

            $existing = $this->embeddedCaption($dbForProject, $video, $code, $name ?? '', $used);
            if (
                $existing !== null
                && $existing->getAttribute('status') === Base::STATUS_READY
                && ($existing->getAttribute('path') ?? '') !== ''
            ) {
                $this->record($extracted, 1, ['result' => 'reused', 'codec' => $codec]);
                $registered++;
                continue;
            }

            $vttPath = \rtrim($outDir, '/') . '/sub_' . $track->index . '.vtt';

            try {
                $encoder->subtitle($inPath, $track->index, $vttPath, self::SUBTITLE_TIMEOUT);
            } catch (\Throwable $th) {
                $this->record($extracted, 1, ['result' => 'failed', 'codec' => $codec]);
                $failed++;
                $retry = true;
                if ($failed === 1) {
                    Span::add('captions.error.stage', 'extract');
                    Span::add('captions.error.stream', $track->index);
                    Span::add('captions.error.codec', $codec);
                    Span::add('warning.message', 'Embedded caption extraction incomplete: stream ' . $track->index . ' (' . $codec . '): ' . $th->getMessage());
                    if ($th instanceof Runtime) {
                        $this->runtime($telemetry, $th, 'captions.error', 'extract');
                    }
                }
                continue;
            }

            if (!\is_file($vttPath) || \filesize($vttPath) === 0) {
                $this->record($extracted, 1, ['result' => 'skipped_empty', 'codec' => $codec]);
                $skipped++;
                Span::add('captions.empty.stream', $track->index);
                Span::add('captions.empty.codec', $codec);
                // A failed extract already owns warning.message; keep that one.
                if ($failed === 0) {
                    Span::add('warning.message', 'Empty VTT for caption stream ' . $track->index);
                }
                continue;
            }

            $caption = null;
            try {
                if ($existing !== null) {
                    $caption = $existing;
                } else {
                    // Re-check immediately before write: an upload can claim default while
                    // ffmpeg is extracting, and a stale snapshot would create two defaults.
                    $isDefault = false;
                    if (!$assignedDefault && $track->default && !$this->hasDefaultCaption($dbForProject, $video)) {
                        $isDefault = true;
                        $assignedDefault = true;
                    }

                    $caption = $dbForProject->createDocument('videosCaptions', new Document([
                        '$id' => Id::unique(),
                        'videoId' => $video->getId(),
                        'videoInternalId' => $video->getSequence(),
                        'name' => $name,
                        'code' => $code,
                        'default' => $isDefault,
                        'status' => Base::STATUS_STARTED,
                    ]));
                    // Reserve this row before the next track. Two untitled
                    // streams share a language and name; without this the
                    // second one finds the row just created and skips it.
                    $used[$caption->getId()] = true;
                    $this->notify(
                        $queueForRealtime,
                        $project,
                        $caption,
                        'videos.[videoId].captions.[captionId].update',
                        [
                            'videoId' => $video->getId(),
                            'captionId' => $caption->getId(),
                        ],
                        $permissions
                    );
                }

                $this->persistCaptionVtt(
                    $dbForProject,
                    $deviceForVideos,
                    $video,
                    $caption,
                    $vttPath,
                    $queueForRealtime,
                    $project,
                    $permissions
                );
                $registered++;
                $this->record($extracted, 1, ['result' => 'registered', 'codec' => $codec]);
            } catch (\Throwable $th) {
                $this->record($extracted, 1, ['result' => 'failed', 'codec' => $codec]);
                $failed++;
                $retry = true;
                if ($failed === 1) {
                    Span::add('captions.error.stage', 'persist');
                    Span::add('captions.error.stream', $track->index);
                    Span::add('captions.error.codec', $codec);
                    Span::add('warning.message', 'Embedded caption registration failed: stream ' . $track->index . ' (' . $codec . '): ' . $th->getMessage());
                }
                // Park the row instead of leaving it `started` until maintenance
                // aborts it; captionsExtracted is released below so a later job retries.
                if ($caption !== null) {
                    $current = $dbForProject->getDocument('videosCaptions', $caption->getId());
                    if (!$current->isEmpty() && !\in_array($current->getAttribute('status'), [Base::STATUS_ABORTED, Base::STATUS_ERROR, Base::STATUS_READY], true)) {
                        try {
                            $caption = $dbForProject->updateDocument('videosCaptions', $caption->getId(), new Document([
                                'status' => Base::STATUS_ERROR,
                                'error' => $this->reason($th, 'persist'),
                            ]));
                            $this->notify(
                                $queueForRealtime,
                                $project,
                                $caption,
                                'videos.[videoId].captions.[captionId].update',
                                [
                                    'videoId' => $video->getId(),
                                    'captionId' => $caption->getId(),
                                ],
                                $permissions
                            );
                        } catch (\Throwable $park) {
                            // The failed extract or registration owns warning.message.
                            $this->log('Videos worker: failed to park embedded caption ' . $caption->getId() . ': ' . $park->getMessage(), 'error');
                            Span::add('captions.error.park_failed', true);
                        }
                    }
                }
            }
        }

        // If no stream was flagged default, promote the first extracted track
        // when no other track already claims default.
        if (!$assignedDefault && !$this->hasDefaultCaption($dbForProject, $video)) {
            $embedded = $dbForProject->find('videosCaptions', [
                Query::equal('videoInternalId', [$video->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($embedded as $caption) {
                if (!empty($caption->getAttribute('fileId', ''))) {
                    continue;
                }

                $caption = $dbForProject->updateDocument(
                    'videosCaptions',
                    $caption->getId(),
                    new Document(['default' => true])
                );
                $this->notify(
                    $queueForRealtime,
                    $project,
                    $caption,
                    'videos.[videoId].captions.[captionId].update',
                    [
                        'videoId' => $video->getId(),
                        'captionId' => $caption->getId(),
                    ],
                    $permissions
                );
                Span::add('captions.default.id', $caption->getId());
                break;
            }
        }

        Span::add('captions.extracted', $registered > 0);
        Span::add('captions.registered', $registered);
        Span::add('captions.skipped', $skipped);
        Span::add('captions.failed', $failed);
        Span::add('captions.retry', $retry);

        return !$retry;
    }

    /**
     * Whether any caption on this video is already marked default.
     */
    private function hasDefaultCaption(Database $dbForProject, Document $video): bool
    {
        $existing = $dbForProject->find('videosCaptions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('default', [true]),
            Query::limit(1),
        ]);

        return $existing !== [];
    }

    /**
     * Embedded caption already stored for this language and name.
     *
     * Uploads carry a fileId and are ignored. `$used` keeps a second stream
     * with the same language and name from attaching to the first row.
     *
     * @param array<string, true> $used
     */
    private function embeddedCaption(
        Database $dbForProject,
        Document $video,
        string $code,
        string $name,
        array &$used
    ): ?Document {
        $existing = $dbForProject->find('videosCaptions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('code', [$code]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        foreach ($existing as $caption) {
            if (!empty($caption->getAttribute('fileId', ''))) {
                continue;
            }
            if ((string) ($caption->getAttribute('name') ?? '') !== $name) {
                continue;
            }
            if (isset($used[$caption->getId()])) {
                continue;
            }

            $used[$caption->getId()] = true;

            return $caption;
        }

        return null;
    }

    /**
     * Map a container language tag to an ISO 639-2 code2 used by the API.
     *
     * Tries the full BCP 47 tag first (zh-cn, pt-br, …) against locale
     * `code` / `code2`, then the primary subtag, then a bare 3-letter code.
     */
    private function captionLanguageCode(?string $language): string
    {
        if ($language === null || $language === '') {
            return 'und';
        }

        $tag = \strtolower(\str_replace('_', '-', \trim($language)));
        $primary = \explode('-', $tag)[0];
        $languages = Config::getParam('locale-languages');

        foreach ($languages as $entry) {
            if (($entry['code'] ?? '') === $tag || ($entry['code2'] ?? '') === $tag) {
                return $entry['code2'];
            }
        }

        foreach ($languages as $entry) {
            if (($entry['code'] ?? '') === $primary || ($entry['code2'] ?? '') === $primary) {
                return $entry['code2'];
            }
        }

        if (\strlen($primary) === 3 && \ctype_alpha($primary)) {
            return $primary;
        }

        return 'und';
    }

    /**
     * Per-job directories under `videos-tmp/jobs/{stamp}~…~{type}-{jobId}/in|out/`.
     *
     * Encode passes the rendition id so each workspace is isolated. Call only
     * after this run has claimed the rendition (pending→started): cleanup is
     * keyed by the same id, and a no-op redelivery must not mkdir+rm the tree
     * an in-flight encode is writing. Timeline and caption jobs pass a uniqid.
     *
     * @return array{basePath: string, inDir: string, outDir: string}
     */
    private function jobWorkspace(
        string $projectId,
        string $videoId,
        string $type,
        string $jobId
    ): array {
        $basePath = Base::tmpJobPath($projectId, $videoId, $type, $jobId);
        $inDir = $basePath . '/in/';
        $outDir = $basePath . '/out/';

        if (!\mkdir($inDir, 0755, true) && !\is_dir($inDir)) {
            throw new \Exception('Failed to create temp input directory');
        }
        if (!\mkdir($outDir, 0755, true) && !\is_dir($outDir)) {
            throw new \Exception('Failed to create temp output directory');
        }

        Span::add('workspace.path', $basePath);

        return [
            'basePath' => $basePath,
            'inDir' => $inDir,
            'outDir' => $outDir,
        ];
    }

    /**
     * Find where the source is read from, copying it into the job directory
     * only when it must be, probe metadata once, and extract embedded captions.
     * A failed extract clears captionsExtracted so a later job can retry.
     *
     * @param array{basePath: string, inDir: string, outDir: string} $workspace
     * @param array<string> $permissions
     * @return array{0: Document, 1: string, 2: array<string, string>} the video, the path or URL of its source, and the headers a URL is requested with
     */
    private function prepareSource(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Document $video,
        array $workspace,
        Realtime $queueForRealtime,
        Document $project,
        array $permissions,
        Telemetry $telemetry,
        string $action,
        string &$stage
    ): array {
        if ($video->isEmpty()) {
            throw new \Exception('Video not found');
        }

        $stage = 'download';
        $phaseStart = \microtime(true);
        $file = $this->resolveFile($dbForProject, $video);
        Span::add('source.name', (string) $file->getAttribute('name', ''));
        Span::add('source.path', (string) $file->getAttribute('path', ''));
        Span::add('source.mime', (string) $file->getAttribute('mimeType', ''));
        Span::add('source.size', (int) $file->getAttribute('sizeOriginal', 0));
        Span::add('source.encrypted', !empty($file->getAttribute('openSSLCipher')));
        Span::add('source.compression', (string) $file->getAttribute('algorithm', Compression::NONE));
        // A copy can take as long as the file is large, and the span is printed
        // only when the job ends.
        $this->log(
            'Reading video ' . $video->getId() . ' of project ' . $project->getId()
            . ' for ' . $action . ' (' . (int) $file->getAttribute('sizeOriginal', 0) . ' bytes)'
        );
        [$inPath, $headers, $read, $why] = $this->source($deviceForFiles, $file, $project, $workspace['inDir']);
        Span::add('source.read', $read);
        Span::add('source.reason', $why);
        $this->log(
            'Read video ' . $video->getId() . ' of project ' . $project->getId()
            . ' via ' . $read . ': ' . $why
        );
        $this->phase($telemetry, $action, 'download', $phaseStart);

        if ((int) $video->getAttribute('duration', 0) <= 0) {
            $stage = 'probe';
            $phaseStart = \microtime(true);
            $video = $this->probe($dbForProject, $video, $inPath, $this->encoder(headers: $headers));
            $this->phase($telemetry, $action, 'probe', $phaseStart);
            $this->notify(
                $queueForRealtime,
                $project,
                $video,
                'videos.[videoId].update',
                ['videoId' => $video->getId()],
                $permissions
            );
        }

        Span::add('source.format', (string) $video->getAttribute('format', ''));
        Span::add('source.duration', (int) $video->getAttribute('duration', 0));
        Span::add('source.width', (int) $video->getAttribute('width', 0));
        Span::add('source.height', (int) $video->getAttribute('height', 0));
        Span::add('source.video_codec', (string) $video->getAttribute('videoCodec', ''));
        Span::add('source.audio_codec', (string) $video->getAttribute('audioCodec', ''));
        Span::add('source.fps', (string) $video->getAttribute('videoFrameRate', ''));

        if (!$video->getAttribute('captionsExtracted', false)) {
            // Claim before extract so two jobs cannot register the same tracks.
            // Release the claim unless extraction finishes, so a later job retries.
            $claimed = $dbForProject->updateDocuments(
                'videos',
                new Document(['captionsExtracted' => true]),
                [
                    Query::equal('$id', [$video->getId()]),
                    Query::equal('captionsExtracted', [false]),
                ]
            );

            if ($claimed > 0) {
                $stage = 'captions';
                $phaseStart = \microtime(true);
                $extracted = false;
                try {
                    $extracted = $this->extractEmbeddedCaptions(
                        $dbForProject,
                        $deviceForVideos,
                        $video,
                        $inPath,
                        $workspace['outDir'],
                        $this->encoder(headers: $headers),
                        $queueForRealtime,
                        $project,
                        $permissions,
                        $telemetry
                    );
                } catch (\Throwable $th) {
                    Span::add('captions.extracted', false);
                    Span::add('captions.error.stage', 'extract');
                    Span::add('warning.message', 'Embedded caption extract failed: ' . $th->getMessage());
                }

                if (!$extracted) {
                    $dbForProject->updateDocuments(
                        'videos',
                        new Document(['captionsExtracted' => false]),
                        [
                            Query::equal('$id', [$video->getId()]),
                            Query::equal('captionsExtracted', [true]),
                        ]
                    );
                }

                $video = $dbForProject->getDocument('videos', $video->getId());
                Span::add('captions.retry', !$extracted);
                $this->phase($telemetry, $action, 'captions', $phaseStart);
            }
        }

        return [$video, $inPath, $headers];
    }

    private function cleanup(string $basePath): void
    {
        $root = \rtrim(APP_STORAGE_VIDEOS_TMP, '/') . '/';
        if ($basePath === '' || !\str_starts_with($basePath, $root)) {
            return;
        }

        // The device walks the tree itself; no shell, no escaping. Its root
        // carries no trailing slash: deletePath() rebuilds nested paths from it.
        // A sweep removing the same tree at the same time fails the walk but
        // leaves nothing behind, which is all that matters here.
        if (!(new Local(\rtrim($root, '/')))->deletePath(\substr($basePath, \strlen($root))) && \is_dir($basePath)) {
            $this->log('Failed removing files from [' . $basePath . ']', 'error');
            Span::add('workspace.cleanup_failed', true);
        }
    }

    /**
     * Load the source file a video or caption was created from.
     *
     * Bucket and file IDs can be reused once deleted, so the documents found
     * under them must still carry the internal IDs recorded when access was
     * checked; anything else is a different file and is treated as missing.
     * Files uploaded before buckets' internal IDs were recorded on them leave
     * the bucket's empty, so only a recorded one is compared.
     */
    private function resolveFile(Database $dbForProject, Document $source): Document
    {
        $bucket = $dbForProject->getDocument('buckets', (string) $source->getAttribute('bucketId', ''));
        $bucketInternalId = (string) $source->getAttribute('bucketInternalId', '');

        if ($bucket->isEmpty() || ($bucketInternalId !== '' && (string) $bucket->getSequence() !== $bucketInternalId)) {
            throw new AppwriteException(AppwriteException::STORAGE_BUCKET_NOT_FOUND);
        }

        $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), (string) $source->getAttribute('fileId', ''));

        if ($file->isEmpty() || (string) $file->getSequence() !== (string) $source->getAttribute('fileInternalId', '')) {
            throw new AppwriteException(AppwriteException::STORAGE_FILE_NOT_FOUND);
        }

        return $file;
    }

    /**
     * Where ffmpeg reads a video source from, copying it only when it must.
     *
     * A file encrypted in segments is read through the Storage download
     * endpoint, which decrypts just the segments each range covers. One
     * encrypted or compressed any other way is decoded whole into the
     * workspace. Otherwise a local device's file is read where it is, and an
     * S3 device's through a presigned URL. ffmpeg range-reads either URL, so
     * the workspace never holds a copy. Both carry a credential: the presigned
     * URL in its query, the endpoint in the returned headers. They are kept off
     * spans, logs and stored errors, and only `source.path` is recorded. An
     * encode the credential cannot outlive is copied instead.
     *
     * @return array{string, array<string, string>, string, string} the path or URL, the headers a URL is requested with, how it is read (appwrite, s3, local or copy), and why
     */
    private function source(Device $deviceForFiles, Document $file, Document $project, string $inDir): array
    {
        $fullPath = (string) $file->getAttribute('path', '');
        $encoded = !empty($file->getAttribute('openSSLCipher'))
            || $file->getAttribute('algorithm', Compression::NONE) !== Compression::NONE;
        $device = $deviceForFiles instanceof TelemetryDevice ? $deviceForFiles->getDevice() : $deviceForFiles;

        // The credential has to outlive the longest encode the timeout allows,
        // plus the probe and caption passes before it. A timeout of 0 leaves
        // that encode unbounded, so it is copied: a credential that expires
        // mid-read fails a later range request. Clamping the lifetime instead
        // would hide the same gap.
        $cap = $this->cap();
        $expires = $cap + self::PRESIGN_SLACK;

        // Why the source is copied, when it is.
        $compression = (string) $file->getAttribute('algorithm', Compression::NONE);
        $why = match (true) {
            !$encoded => '',
            empty($file->getAttribute('openSSLCipher')) => 'compressed with ' . $compression,
            $compression !== Compression::NONE => 'encrypted, and compressed with ' . $compression,
            default => 'encrypted whole, not in segments',
        };

        if ((new Encryption($file))->isSegmented()) {
            // The host the API answers on from inside the network; none means
            // the worker has no way to reach it.
            $host = System::getEnv('_APP_MIGRATION_HOST', '');
            $why = match (true) {
                $host === '' => 'encrypted in segments, and no API host is set',
                $cap <= 0 => 'encrypted in segments, and no encode timeout bounds an API key',
                $expires > self::KEY_SECONDS => 'encrypted in segments, and the encode timeout outlasts an API key',
                default => '',
            };

            if ($why === '') {
                $url = 'http://' . $host . '/v1/storage/buckets/' . $file->getAttribute('bucketId')
                    . '/files/' . $file->getId() . '/download';
                // A key is only taken with the project it was issued for named beside it.
                $headers = [
                    'X-Appwrite-Project' => $project->getId(),
                    'X-Appwrite-Key' => $this->key($project, $expires),
                ];

                $refusal = $this->refusal($url, $headers);
                if ($refusal === '') {
                    return [$url, $headers, 'appwrite', 'encrypted in segments, decrypted by the API range by range'];
                }

                // The API can refuse a project the worker may still read for:
                // one that is paused, over a limit, or behind its own rules.
                // Said now, as the copy that follows can take a while.
                Span::add('source.fallback', true);
                $this->log(
                    'Storage API did not serve file ' . $file->getId() . ' of project ' . $project->getId()
                    . ' (' . $refusal . '), copying it instead',
                    'warning'
                );
                $why = 'encrypted in segments, and the API did not serve it: ' . $refusal;
            }
        }

        if ($encoded) {
            return [$this->download($deviceForFiles, $file, $inDir), [], 'copy', $why];
        }

        if (!$device instanceof Local && !$device instanceof S3) {
            return [$this->download($deviceForFiles, $file, $inDir), [], 'copy', 'its device is neither local nor S3'];
        }

        if (!$deviceForFiles->exists($fullPath)) {
            // The path is on the span as source.path.
            throw new AppwriteException(AppwriteException::STORAGE_FILE_NOT_FOUND);
        }

        if ($device instanceof Local) {
            return [$fullPath, [], 'local', 'stored as is on a local device'];
        }

        if ($cap <= 0) {
            return [$this->download($deviceForFiles, $file, $inDir), [], 'copy', 'no encode timeout bounds a presigned URL'];
        }

        // SigV4 will not sign past 7 days.
        if ($expires > S3::MAX_PRESIGN_SECONDS) {
            return [$this->download($deviceForFiles, $file, $inDir), [], 'copy', 'the encode timeout outlasts a presigned URL'];
        }

        return [$device->getPresignedUrl($fullPath, $expires), [], 's3', 'stored as is on S3'];
    }

    /**
     * An API key for this job alone: it reads files, for as long as the job may
     * run, and its requests are not counted as the project's traffic.
     */
    private function key(Document $project, int $seconds): string
    {
        $jwt = new JWT(EncryptionKey::current(), 'HS256', $seconds, 0);

        return API_KEY_EPHEMERAL . '_' . $jwt->encode([
            Key::PURPOSE_CLAIM => Key::PURPOSE,
            'projectId' => $project->getId(),
            'projectSequence' => $project->getSequence(),
            'disabledMetrics' => [
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
            ],
            'scopes' => ['files.read'],
        ]);
    }

    /**
     * Why the API does not answer a range of the source, asked for its first
     * byte: its status and error type, or the failure to reach it. Empty when
     * it does.
     *
     * @param array<string, string> $headers
     */
    private function refusal(string $url, array $headers): string
    {
        try {
            $response = (new Client(new CurlAdapter()))
                ->withConnectTimeout(self::SOURCE_TIMEOUT)
                ->withTimeout(self::SOURCE_TIMEOUT)
                ->withHeaders([...$headers, 'Range' => 'bytes=0-0'])
                ->sendRequest((new RequestFactory())->createRequest(Method::GET, $url));
        } catch (\Throwable $error) {
            // The class alone: a transport message can quote the request.
            return 'request failed with ' . $error::class;
        }

        if ($response->getStatusCode() === 206) {
            return '';
        }

        $body = \json_decode((string) $response->getBody(), true);
        $type = \is_array($body) && \is_string($body['type'] ?? null) ? ' ' . $body['type'] : '';

        return 'status ' . $response->getStatusCode() . $type;
    }

    /**
     * Download a Storage file into a local temp directory, decrypting and
     * decompressing when needed. Returns the absolute local path.
     */
    private function download(Device $deviceForFiles, Document $file, string $inDir): string
    {
        $fullPath = $file->getAttribute('path', '');
        $basename = \basename($fullPath);
        $localPath = $inDir . $basename;

        if (!$deviceForFiles->exists($fullPath)) {
            // The path is on the span as source.path.
            throw new AppwriteException(AppwriteException::STORAGE_FILE_NOT_FOUND);
        }

        $hasEncryption = !empty($file->getAttribute('openSSLCipher'));
        $compression = $file->getAttribute('algorithm', Compression::NONE);
        $hasCompression = $compression !== Compression::NONE;
        $contentType = (string) $file->getAttribute('mimeType', '');
        $local = new Local('/');

        if (!$hasEncryption && !$hasCompression) {
            if (!$deviceForFiles->copy($fullPath, $localPath, $local)) {
                throw new \Exception('Unable to transfer source to ' . $localPath);
            }

            return $localPath;
        }

        $encryption = new Encryption($file);
        if ($encryption->isSegmented()) {
            // Decrypted segment by segment straight into the workspace, so a
            // source of any size is never held whole in memory.
            $encryption->copy($deviceForFiles, $local, $localPath, $contentType);

            return $localPath;
        }

        // Written before segmented encryption: one envelope over the whole
        // file, possibly compressed, so it is read whole as the storage
        // endpoints read it.
        $data = (string) $deviceForFiles->read($fullPath);
        if ($hasEncryption) {
            $data = $encryption->decrypt($data);
        }
        if ($hasCompression) {
            $decompressed = match ($compression) {
                Compression::ZSTD => (new Zstd())->decompress($data),
                Compression::GZIP => (new GZIP())->decompress($data),
                default => $data,
            };
            // A decompressor reports failure as an empty string. Files stored
            // above the read buffer before 1.5.0 recorded an algorithm they
            // were never compressed with; their bytes are already the source.
            $data = $decompressed === '' && (int) $file->getAttribute('sizeOriginal', 0) > 0
                ? $data
                : $decompressed;
        }
        if (!$local->write($localPath, new Stream($data), $contentType)) {
            throw new \Exception('Unable to write decrypted source to ' . $localPath);
        }

        return $localPath;
    }

    /**
     * Probe the source and sparsely update the videos document.
     */
    private function probe(
        Database $dbForProject,
        Document $video,
        string $inPath,
        ?Encoder $encoder = null
    ): Document {
        try {
            $info = ($encoder ?? $this->encoder())->probe($inPath);
        } catch (Runtime $th) {
            // ffprobe rejecting the input is the one place that proves the file
            // is bad; a timeout or a binary that would not start stays internal.
            if (!\str_contains($th->getMessage(), 'failed with exit code')) {
                throw $th;
            }
            $message = \rtrim((new AppwriteException(AppwriteException::VIDEO_NOT_VALID))->getMessage(), '.');
            $verdict = $this->verdict($th);
            throw new AppwriteException(
                AppwriteException::VIDEO_NOT_VALID,
                $message . ($verdict !== '' ? ' (ffprobe: ' . $verdict . ')' : ''),
                previous: $th
            );
        }
        $attrs = $this->attributes($info);

        return $dbForProject->updateDocument(
            'videos',
            $video->getId(),
            new Document($attrs)
        );
    }

    /**
     * Map Utopia\Video\Info onto the videos collection attribute names.
     *
     * @return array<string, mixed>
     */
    private function attributes(Info $info): array
    {
        $videoFormat = $info->videoFormat ?? '';
        $audioFormat = $info->audioFormat ?? '';

        return [
            'duration' => $info->milliseconds(),
            'format' => $info->format,
            'height' => $info->height ?? 0,
            'width' => $info->width ?? 0,
            'aspectRatio' => $info->ratio() ?? '',
            'videoFormat' => $videoFormat,
            'videoFormatProfile' => $info->videoProfile ?? '',
            'videoFrameRate' => $info->fps !== null ? (string) $info->fps : '',
            'videoFrameRateMode' => $info->fpsMode ?? '',
            'videoBitRate' => $info->videoBitrate ?? 0,
            'videoCodec' => $info->videoCodec ?? $videoFormat,
            'audioFormat' => $audioFormat,
            'audioSampleRate' => $info->sampleRate !== null ? (string) $info->sampleRate : '',
            'audioBitRate' => $info->audioBitrate ?? 0,
            'audioCodec' => $info->audioCodec ?? $audioFormat,
        ];
    }

    /**
     * Persist segment rows and build the metadata shape playback endpoints expect.
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function persistPackage(
        Database $dbForProject,
        Package $package,
        Document $rendition,
        string $path,
        string $output
    ): array {
        $longest = 0.0;
        $declared = 0.0;
        $streams = [];

        foreach ($package->variants() as $index => $variant) {
            $streamId = $index;
            $streams[] = $this->streamMeta($variant, $streamId);

            if ($declared <= 0.0 && $variant->target > 0) {
                $declared = $variant->target;
            }

            foreach ($variant->segments as $segment) {
                if (!$segment->init && $segment->duration > $longest) {
                    $longest = $segment->duration;
                }

                $dbForProject->createDocument('videosRenditionsSegments', new Document(\array_filter([
                    'renditionId' => $rendition->getId(),
                    'renditionInternalId' => $rendition->getSequence(),
                    'streamId' => $streamId,
                    'fileName' => $segment->file,
                    'path' => $path,
                    // DASH seeks from these lengths. A missing one falls the
                    // manifest back to a single duration for every segment.
                    'duration' => (!$segment->init && $segment->duration > 0)
                        ? DashManifest::duration($segment->duration)
                        : null,
                    'isInit' => $segment->init ? 1 : 0,
                    // Bytes of this segment on the videos device, taken before
                    // the file left scratch.
                    'size' => $segment->size,
                ], fn ($value) => $value !== null)));
            }
        }

        $targetDuration = $longest > 0
            ? (string) (int) \ceil($longest)
            : ($declared > 0 ? (string) (int) \ceil($declared) : null);

        if ($output === Base::OUTPUT_HLS || $output === Base::OUTPUT_CMAF) {
            $metaTarget = $package->metadata()['targetDuration'] ?? null;
            if ($targetDuration === null && $metaTarget !== null && (float) $metaTarget > 0) {
                $targetDuration = (string) (int) \ceil((float) $metaTarget);
            }
        }

        return match ($output) {
            Base::OUTPUT_HLS => [['hls' => $streams], $targetDuration],
            Base::OUTPUT_CMAF => [['hls' => $streams, 'mpd' => $this->mpdMeta($package)], $targetDuration],
            default => [['mpd' => $this->mpdMeta($package)], $targetDuration],
        };
    }

    /**
     * Strips characters that could break out of a quoted manifest value.
     *
     * Track names, language tags and codec strings come from the container
     * metadata of user-uploaded files, and both HLS attribute lists and MPD
     * XML attributes are quote- and line-delimited: a `"` or newline in a
     * value would let one uploader inject playlist lines or XML into the
     * manifest served to every other viewer. Cleaning at persist time means
     * the playback endpoints can render the stored metadata verbatim.
     *
     * Implemented with string walks (no preg_*) per AGENTS.md: ASCII alnum
     * plus a fixed punctuation set, and any non-ASCII code point (titles in
     * other scripts). Breakout vectors for these manifests are ASCII.
     */
    private function sanitizeMeta(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $allowed = [
            ' ' => true, '.' => true, ',' => true, ':' => true, ';' => true,
            '/' => true, '@' => true, '=' => true, '+' => true, '_' => true, '-' => true,
        ];
        $out = '';
        $length = \mb_strlen($value, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = \mb_substr($value, $i, 1, 'UTF-8');
            if (isset($allowed[$char])) {
                $out .= $char;
                continue;
            }
            if (\strlen($char) === 1 && \ctype_alnum($char)) {
                $out .= $char;
                continue;
            }
            if (\strlen($char) > 1) {
                $out .= $char;
            }
        }

        return $out;
    }

    /**
     * Applies sanitizeMeta() to every string value of an attribute map.
     *
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private function sanitizeMetaMap(array $attributes): array
    {
        return \array_map(fn (string $value) => (string) $this->sanitizeMeta($value), $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function streamMeta(Variant $variant, int $streamId): array
    {
        $entry = [
            'id' => $streamId,
            'type' => $variant->type,
        ];

        if ($variant->playlist !== null) {
            $entry['path'] = \basename($variant->playlist);
        }
        if ($variant->language !== null) {
            $entry['language'] = $this->sanitizeMeta($variant->language);
            $entry['name'] = $this->sanitizeMeta($variant->language);
        }
        if ($variant->resolution() !== null) {
            $entry['resolution'] = $this->sanitizeMeta($variant->resolution());
        }
        if ($variant->bandwidth > 0) {
            $entry['bandwidth'] = (string) $variant->bandwidth;
        }
        if ($variant->codecs !== null) {
            $entry['codecs'] = $this->sanitizeMeta($variant->codecs);
        }

        return $entry;
    }

    /**
     * Build the metadata.mpd shape the DASH playback endpoint expects.
     *
     * @return array{attributes: array<string, string>, adaptations: list<array<string, mixed>>}
     */
    private function mpdMeta(Package $package): array
    {
        $raw = $package->metadata();
        $attributes = [];

        foreach (['profiles', 'type', 'mediaPresentationDuration', 'maxSegmentDuration', 'minBufferTime'] as $key) {
            if (!empty($raw[$key])) {
                $attributes[$key] = (string) $this->sanitizeMeta((string) $raw[$key]);
            }
        }

        $adaptations = [];

        foreach ($package->variants() as $index => $variant) {
            $representationAttrs = \array_filter([
                'id' => $variant->id,
                'mimeType' => $variant->mimeType,
                'codecs' => $variant->codecs,
                'bandwidth' => $variant->bandwidth > 0 ? (string) $variant->bandwidth : null,
                'width' => $variant->width !== null ? (string) $variant->width : null,
                'height' => $variant->height !== null ? (string) $variant->height : null,
                'sar' => $variant->sar,
                'audioSamplingRate' => $variant->sampleRate !== null ? (string) $variant->sampleRate : null,
            ], fn ($value) => $value !== null && $value !== '');

            $segmentListAttrs = \array_filter([
                'timescale' => $variant->timescale > 0 ? (string) $variant->timescale : null,
                // Fallback for encodes that did not store a duration per segment.
                // It is one value — the packager target — and the manifest drops
                // it once a SegmentTimeline can name each segment.
                'duration' => ($variant->timescale > 0 && $variant->target > 0)
                    ? (string) (int) \round($variant->target * $variant->timescale)
                    : null,
                'startNumber' => $variant->startNumber > 0 ? (string) $variant->startNumber : null,
            ], fn ($value) => $value !== null);

            $adaptationAttrs = \array_filter([
                'contentType' => $variant->type,
                'lang' => $variant->language,
            ], fn ($value) => $value !== null && $value !== '');

            $adaptations[] = [
                'id' => $index,
                'attributes' => $this->sanitizeMetaMap($adaptationAttrs),
                'representation' => [
                    'attributes' => $this->sanitizeMetaMap($representationAttrs),
                    'segmentList' => [
                        'attributes' => $this->sanitizeMetaMap($segmentListAttrs),
                    ],
                ],
            ];
        }

        return [
            'attributes' => $attributes,
            'adaptations' => $adaptations,
        ];
    }

    /**
     * @param int $timeout Seconds an ffmpeg run may take; 0 disables the limit.
     * @param array<string, string> $headers Headers a source read from the API is requested with.
     */
    private function encoder(int $timeout = 0, array $headers = []): Encoder
    {
        // Silent reporters: failures reach the span through the exception, so
        // the library must not print its own copy to stdout.
        // Sources are user uploads: only plain media containers, opened from
        // the workspace or a presigned URL, so nothing in them is followed.
        $probe = new FFprobe(protocols: Base::SOURCE_PROTOCOLS, formats: Base::SOURCE_FORMATS, options: Base::SOURCE_OPTIONS, headers: $headers);
        $probe->setReporter(new Silent());

        return new Encoder(
            new FFmpeg(timeout: $timeout, threads: $this->threads(), protocols: Base::SOURCE_PROTOCOLS, formats: Base::SOURCE_FORMATS, options: Base::SOURCE_OPTIONS, headers: $headers),
            $probe,
            new Silent()
        );
    }

    /**
     * ffmpeg worker threads per job, from _APP_VIDEOS_THREADS; also exported as
     * encoder.threads.
     */
    private function threads(): int
    {
        return (int) System::getEnv('_APP_VIDEOS_THREADS', '4');
    }

    /**
     * Encode size billed as executions.mbSeconds with service videos: the
     * container's effective memory (MiB) and CPU, capped by the catalog row
     * for `_APP_VIDEOS_SPECIFICATION` so unbounded workers do not bill the host.
     *
     * @return array{memory: int, cpus: float}
     */
    private function specification(): array
    {
        $measuredMemory = System::getMemory();
        $measuredCpus = System::getCPU();
        // Strictly less than the host total means a cgroup limit is in effect.
        // Equal means unlimited (or a limit equal to the host), so not "from the container".
        $memoryFromContainer = $measuredMemory < System::getMemoryTotal();
        $cpusFromContainer = $measuredCpus < (float) System::getCPUCores();

        $billed = VideoUsage::encodeSpecification(
            System::getEnv('_APP_VIDEOS_SPECIFICATION', Specification::S_4VCPU_4GB),
            $measuredMemory,
            $measuredCpus,
        );

        $this->log(\sprintf(
            'Encode bill size: memory=%dMiB (measured=%dMiB, fromContainer=%s) cpus=%g (measured=%g, fromContainer=%s)',
            $billed['memory'],
            $measuredMemory,
            $memoryFromContainer ? 'yes' : 'no',
            $billed['cpus'],
            $measuredCpus,
            $cpusFromContainer ? 'yes' : 'no',
        ));

        Span::add('encode.bill.memory', $billed['memory']);
        Span::add('encode.bill.cpus', $billed['cpus']);
        Span::add('encode.bill.measured_memory', $measuredMemory);
        Span::add('encode.bill.measured_cpus', $measuredCpus);
        Span::add('encode.bill.memory_from_container', $memoryFromContainer);
        Span::add('encode.bill.cpus_from_container', $cpusFromContainer);

        return $billed;
    }

    /**
     * Seconds one ffmpeg run may take at most, from _APP_VIDEOS_ENCODE_TIMEOUT;
     * 0 disables the limit.
     */
    private function cap(): int
    {
        return (int) System::getEnv('_APP_VIDEOS_ENCODE_TIMEOUT', '21600');
    }

    /**
     * Seconds an ffmpeg run over this source may take. An unknown duration gets the cap.
     */
    private function timeout(Document $video, int $factor): int
    {
        $cap = $this->cap();
        $seconds = (int) \ceil(((int) $video->getAttribute('duration', 0)) / 1000);
        if ($seconds <= 0) {
            return $cap;
        }

        return \min($cap, \max(self::ENCODE_FLOOR, self::ENCODE_BASE + $seconds * $factor));
    }

    /**
     * Telemetry must never fail a job.
     *
     * @param array<string, string|int|float|bool> $attributes
     */
    private function record(Counter|UpDownCounter|Histogram $instrument, float|int $value, array $attributes = []): void
    {
        try {
            if ($instrument instanceof Histogram) {
                $instrument->record($value, $attributes);
            } else {
                $instrument->add($value, $attributes);
            }
        } catch (\Throwable) {
            // Metrics are best-effort.
        }
    }

    /**
     * Closes a timed phase: timings.{phase} on the span and one histogram sample.
     */
    private function phase(Telemetry $telemetry, string $action, string $phase, float $start): void
    {
        $seconds = \round(\microtime(true) - $start, 3);
        Span::add('timings.' . $phase, $seconds);
        $this->record(
            $telemetry->createHistogram(
                'worker.videos.phase.duration',
                's',
                'Videos job phase duration',
                ['ExplicitBucketBoundaries' => self::PHASE_BUCKETS]
            ),
            $seconds,
            ['action' => $action, 'phase' => $phase]
        );
    }

    /**
     * One jobs count and one duration sample per message.
     *
     * @param array<string, string> $attributes
     */
    private function outcome(Telemetry $telemetry, string $action, string $outcome, float $start, array $attributes = []): void
    {
        $labels = ['action' => $action, 'outcome' => $outcome];
        $this->record(
            $telemetry->createCounter('worker.videos.jobs', null, 'Videos jobs by action and outcome'),
            1,
            $labels + $attributes
        );
        $this->record(
            $telemetry->createHistogram(
                'worker.videos.job.duration',
                's',
                'Videos job duration',
                ['ExplicitBucketBoundaries' => self::JOB_BUCKETS]
            ),
            \round(\microtime(true) - $start, 3),
            $labels
        );
    }

    /**
     * Copies a failed ffmpeg or ffprobe run onto the span under {prefix}.* and
     * counts the exit. The library reports a timeout only through its message.
     */
    private function runtime(Telemetry $telemetry, Runtime $runtime, string $prefix, string $purpose): void
    {
        $timedOut = \str_contains($runtime->getMessage(), 'timed out after');
        Span::add($prefix . '.exit_code', $runtime->getCode());
        Span::add($prefix . '.timed_out', $timedOut);
        Span::add($prefix . '.command', \substr($this->withoutSignatures(\implode(' ', $runtime->command())), 0, 2048));
        Span::add($prefix . '.stderr', \substr($this->withoutSignatures($runtime->output()), -4096));
        $this->record(
            $telemetry->createCounter('worker.videos.ffmpeg.exit', null, 'Failed ffmpeg and ffprobe runs by purpose and exit code'),
            1,
            ['purpose' => $purpose, 'exit_code' => (string) $runtime->getCode(), 'timed_out' => $timedOut ? 'true' : 'false']
        );
    }

    /**
     * User-safe text for a parked rendition, caption or timeline. As in the
     * Builds worker, only a failure this worker raised on purpose with a 4xx
     * type (a bad source, a bad caption, a missing file) is the owner's and
     * shown as it is; everything else is internal by default, with the detail
     * left on the span.
     */
    private function reason(\Throwable $th, string $stage): string
    {
        if ($th instanceof AppwriteException && $th->getCode() < 500) {
            return $this->withoutPaths($th->getMessage());
        }

        return 'An internal error occurred during the ' . $stage . ' stage. Please try again, and contact support if the problem persists.';
    }

    /**
     * ffprobe's last stderr line, without paths: the one line that says why a
     * file was rejected ("moov atom not found", "Invalid data found when
     * processing input").
     */
    private function verdict(Runtime $runtime): string
    {
        foreach (\array_reverse(\explode("\n", \str_replace("\r", "\n", $runtime->output()))) as $line) {
            $line = \trim($line);
            if ($line !== '' && $line !== 'Conversion failed!') {
                return $this->withoutPaths($line);
            }
        }

        return '';
    }

    /**
     * Drops absolute paths and URLs from a message; the source path is on the
     * span already, and a presigned source URL is a credential.
     */
    private function withoutPaths(string $text): string
    {
        $words = [];
        foreach (\explode(' ', \str_replace(["\r", "\n", "\t"], ' ', $text)) as $word) {
            $bare = \ltrim($word, '"\'([:,');
            if ($word === '' || \str_starts_with($bare, '/') || \str_contains($bare, '://')) {
                continue;
            }
            $words[] = $word;
        }

        return \mb_substr(\implode(' ', $words), 0, 2048);
    }

    /**
     * Cuts the query string off every URL in a command line or ffmpeg's output,
     * so a presigned source never reaches a span with its signature. A URL runs
     * to the next space or line break; whatever follows its `?` goes, a closing
     * quote included.
     */
    private function withoutSignatures(string $text): string
    {
        $lines = [];
        foreach (\explode("\n", $text) as $line) {
            $words = [];
            foreach (\explode(' ', $line) as $word) {
                $query = \strpos($word, '?');
                if ($query !== false && \str_contains(\substr($word, 0, $query), '://')) {
                    $word = \substr($word, 0, $query);
                }
                $words[] = $word;
            }
            $lines[] = \implode(' ', $words);
        }

        return \implode("\n", $lines);
    }

    /**
     * Whether a mid-run ownership miss is a reclaim (another delivery took
     * startedAt) or a halt (sweeper / delete / park left this claim intact).
     */
    private function encodeLost(Document $current, string $claimStartedAt): string
    {
        if (
            !$current->isEmpty()
            && $claimStartedAt !== ''
            && (string) $current->getAttribute('startedAt', '') !== $claimStartedAt
        ) {
            return 'reclaimed';
        }

        return 'halted';
    }

    /**
     * Removes a prior encode attempt's segment rows and device directory without
     * deleting the rendition row. Same cleanup as Deletes::deleteVideoRendition.
     */
    private function wipeRenditionTrace(Database $dbForProject, Device $deviceForVideos, Document $rendition): void
    {
        $dbForProject->deleteDocuments('videosRenditionsSegments', [
            Query::equal('renditionInternalId', [$rendition->getSequence()]),
        ]);

        $path = (string) $rendition->getAttribute('path', '');
        if ($path === '') {
            return;
        }

        try {
            $deviceForVideos->delete($path, true);
        } catch (\Throwable $th) {
            $this->log(
                'Videos worker: failed to wipe reclaimed rendition ' . $path . ': ' . $th->getMessage(),
                'warning'
            );
            Span::add('rendition.reclaim_cleanup_failed', true);
        }
    }

    /**
     * Removes a prior caption attempt's segment rows and staged VTT without
     * deleting the caption row.
     */
    private function wipeCaptionTrace(Database $dbForProject, Device $deviceForVideos, Document $caption): void
    {
        $dbForProject->deleteDocuments('videosCaptionsSegments', [
            Query::equal('captionInternalId', [$caption->getSequence()]),
        ]);

        $path = (string) $caption->getAttribute('path', '');
        if ($path === '') {
            return;
        }

        try {
            $deviceForVideos->delete($path);
        } catch (\Throwable $th) {
            $this->log(
                'Videos worker: failed to wipe reclaimed caption ' . $path . ': ' . $th->getMessage(),
                'warning'
            );
            Span::add('caption.reclaim_cleanup_failed', true);
        }
    }

    /**
     * Sparse timeline state write on the video row after a claim has won.
     * Conditioned on timelineStatus=started and the ownership token so a
     * reclaim cannot be overwritten by a loser. An empty result means the
     * video was deleted, or another delivery took the claim.
     *
     * @param-out string $claimUpdatedAt
     */
    private function timelineStatus(
        Database $dbForProject,
        Document $video,
        string $status,
        ?string $error,
        string &$claimUpdatedAt
    ): Document {
        $updated = $dbForProject->updateDocuments(
            'videos',
            new Document([
                'timelineStatus' => $status,
                'timelineError' => $error,
            ]),
            [
                Query::equal('$id', [$video->getId()]),
                Query::equal('timelineStatus', [Base::STATUS_STARTED]),
                Query::equal('$updatedAt', [$claimUpdatedAt]),
            ]
        );
        if ($updated === 0) {
            return new Document([]);
        }

        $parked = $dbForProject->getDocument('videos', $video->getId());
        if (!$parked->isEmpty()) {
            $claimUpdatedAt = (string) $parked->getUpdatedAt();
        }

        return $parked;
    }

    /**
     * Read permissions to stamp onto realtime payloads so delivery matches
     * getAuthorizedVideo(): only the video's own read roles. A video without
     * an ACL yields an empty set and session events are dropped. Console
     * delivery is routed separately via setSubscribers() — do not broaden
     * roles with the project team.
     *
     * @return array<string>
     */
    private function readPermissions(Document $video): array
    {
        return \array_map(
            static fn (string $role) => Permission::read(Role::parse($role)),
            $video->getPermissionsByType(PermissionType::Read)
        );
    }

    /**
     * Publishes a document change on the project's realtime channels.
     *
     * The Realtime adapter derives delivery roles from the payload's read
     * permissions — an empty set means the event is silently dropped. A payload
     * with its own `$permissions` (a video with an ACL) keeps them. Otherwise
     * (video child rows, timeline payloads, a video without an ACL) the given
     * $permissions are stamped on from readPermissions() — the video's read
     * roles only. A video without an ACL produces no session events. Console
     * updates are routed via setSubscribers(['console', …]).
     *
     * @param array<string, string> $params
     * @param array<string> $permissions
     */
    private function notify(
        Realtime $queueForRealtime,
        Document $project,
        Document $document,
        string $event,
        array $params,
        array $permissions
    ): void {
        $payload = $document->getArrayCopy();
        if (empty($payload['$permissions'])) {
            $payload['$permissions'] = $permissions;
        }

        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent($event);

        foreach ($params as $key => $value) {
            $queueForRealtime->setParam($key, $value);
        }

        $queueForRealtime
            ->setPayload($payload)
            ->trigger();
    }

    /**
     * Log a job message with a UTC second timestamp.
     */
    private function log(string $message, string $type = 'info'): void
    {
        $line = '[' . \gmdate('Y-m-d\TH:i:s') . '] ' . $message;
        match ($type) {
            'error' => Console::error($line),
            'warning' => Console::warning($line),
            default => Console::info($line),
        };
    }
}
