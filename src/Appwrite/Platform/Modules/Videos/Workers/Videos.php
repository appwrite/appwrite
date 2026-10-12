<?php

namespace Appwrite\Platform\Modules\Videos\Workers;

use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\Usage\Context;
use Appwrite\Usage\Video as VideoUsage;
use Captioning\Format\SubripFile;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
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

/**
 * Consumes the `videos` queue: sprite timelines, caption packaging and
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

    /** ffmpeg worker threads per job; also exported as encoder.threads. */
    private const THREADS = 4;

    /** Seconds one embedded caption track may take to extract before it counts as failed. */
    private const SUBTITLE_TIMEOUT = 60;

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

        $this->record(
            $telemetry->createHistogram(
                'worker.videos.queue.wait',
                's',
                'Seconds a videos message waited before a worker picked it up',
                ['ExplicitBucketBoundaries' => self::WAIT_BUCKETS]
            ),
            \max(0, \time() - $message->getTimestamp()),
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
     * Probe the source, tile sprite sheets and emit a relative WebVTT timeline.
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
        $workspace = $this->jobWorkspace(
            $projectId,
            $video->getId(),
            Base::JOB_TIMELINE,
            \uniqid('', true)
        );
        $permissions = $this->readPermissions($video);
        $started = \microtime(true);
        $stage = 'download';
        $outcome = 'error';

        try {
            Console::info('Videos worker: timeline started for video ' . $video->getId());
            [$video, $inPath] = $this->prepareSource(
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

            $this->notify(
                $queueForRealtime,
                $project,
                $video,
                'videos.[videoId].timelines.update',
                ['videoId' => $video->getId()],
                $permissions
            );

            $encoder = $this->encoder();

            // Prefer dimensions over bitrate: many containers (VBR MKV, some DivX)
            // report width/height but leave bitrate as 0, and empty(0) is true in PHP.
            $width = (int) $video->getAttribute('width', 0);
            $height = (int) $video->getAttribute('height', 0);

            if ($width <= 0 || $height <= 0) {
                Span::add('warning.message', 'Source has no video track; timeline skipped');
                $outcome = 'skipped';
                $this->notify(
                    $queueForRealtime,
                    $project,
                    $video,
                    'videos.[videoId].timelines.update',
                    ['videoId' => $video->getId()],
                    $permissions
                );
                return;
            }

            // Wipe previous sprites so a source-file update can regenerate cleanly
            // against the UNIQUE (videoId, name) index. Leave timeline.vtt for an
            // in-place size update in persistTimelineVtt().
            $existing = $dbForProject->find('videos_timelines', [
                Query::equal('videoInternalId', [$video->getSequence()]),
                Query::notEqual('name', ['timeline.vtt']),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($existing as $sprite) {
                $path = $sprite->getAttribute('path', '');
                if (!empty($path) && $deviceForVideos->exists($path)) {
                    try {
                        $deviceForVideos->delete($path);
                    } catch (\Throwable) {
                        // Best-effort; the DB row is the source of truth for readiness.
                    }
                }
                $dbForProject->deleteDocument('videos_timelines', $sprite->getId());
            }

            // Appwrite rewrites sheet URLs to sprite endpoints, so skip the
            // library-written VTT and render our own from cues().
            $stage = 'tile';
            $phaseStart = \microtime(true);
            $sheet = $encoder->tile(
                $inPath,
                \rtrim($workspace['outDir'], '/'),
                // 480px stays sharp on HiDPI screens and enlarged sprites; a 4x4
                // grid keeps each sheet at ~1920x1080, small enough for hover scrubbing.
                (new Tile())->width(480)->grid(4, 4)->quality(2)->vtt(false)
            );

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

                $sprite = $dbForProject->createDocument('videos_timelines', new Document([
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
                // to /v1/videos/{videoId}/sprites/{spriteId}.
                $urls[$fileName] = 'sprites/' . $sprite->getId();
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

            $this->notify(
                $queueForRealtime,
                $project,
                $video,
                'videos.[videoId].timelines.update',
                ['videoId' => $video->getId()],
                $permissions
            );
            $outcome = 'ready';
        } catch (\Throwable $th) {
            Span::add('error.stage', $stage);
            if ($th instanceof Runtime) {
                $this->runtime($telemetry, $th, 'ffmpeg', $stage);
            }

            $this->notify(
                $queueForRealtime,
                $project,
                $video,
                'videos.[videoId].timelines.update',
                ['videoId' => $video->getId()],
                $permissions
            );
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
            $this->cleanup($workspace['basePath']);
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
        $workspace = $this->jobWorkspace(
            $videoMessage->project->getId(),
            $video->getId(),
            Base::JOB_CAPTION,
            \uniqid('', true)
        );
        $permissions = $this->readPermissions($video);
        Span::add('caption.code', (string) $caption->getAttribute('code', ''));
        Span::add('caption.default', (bool) $caption->getAttribute('default', false));
        Span::add('caption.bucket.id', (string) $caption->getAttribute('bucketId', ''));
        Span::add('caption.file.id', (string) $caption->getAttribute('fileId', ''));
        $started = \microtime(true);
        $stage = 'claim';
        $outcome = 'error';
        $conversion = '';

        try {
            $caption = $dbForProject->updateDocument(
                'videos_captions',
                $caption->getId(),
                new Document([
                    'status' => Base::STATUS_STARTED,
                ])
            );
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

            $stage = 'download';
            $phaseStart = \microtime(true);
            $file = $this->resolveFile(
                $dbForProject,
                $caption->getAttribute('bucketId', ''),
                $caption->getAttribute('fileId', '')
            );
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
                try {
                    $this->subripToWebvtt($downloaded, $captionPath);
                } catch (\Throwable $parse) {
                    Span::add('warning.message', 'SubRip parse failed: ' . $parse->getMessage());
                    throw new \Exception(
                        'Failed to convert caption to WebVTT: ' . $parse->getMessage(),
                        previous: $parse
                    );
                }
            }
            $this->phase($telemetry, VideoAction::Caption->value, 'convert', $phaseStart);
            Span::add('caption.conversion', $conversion);

            $stage = 'persist';
            $phaseStart = \microtime(true);
            $caption = $this->persistCaptionVtt(
                $dbForProject,
                $deviceForVideos,
                $video,
                $caption,
                $captionPath,
                $queueForRealtime,
                $project,
                $permissions
            );
            $this->phase($telemetry, VideoAction::Caption->value, 'persist', $phaseStart);
            Span::add('caption.size', (int) $caption->getAttribute('size', 0));
            $outcome = 'ready';
        } catch (\Throwable $th) {
            Span::add('error.stage', $stage);
            $caption = $dbForProject->updateDocument(
                'videos_captions',
                $caption->getId(),
                new Document([
                    'status' => Base::STATUS_ERROR,
                ])
            );
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
            $this->cleanup($workspace['basePath']);
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
        $startedAt = \microtime(true);
        $storageBytes = 0;
        $output = $videoMessage->output !== ''
            ? $videoMessage->output
            : (string) $rendition->getAttribute('output', Base::OUTPUT_HLS);
        $permissions = $this->readPermissions($videoMessage->video);
        $claimed = false;
        $codec = Base::normalizeCodec(
            $rendition->getAttribute('codec')
                ?: $profile->getAttribute('codec')
        );
        $stage = 'claim';
        $outcome = 'error';
        $status = '';
        $last = null;

        try {
            // Every rendition owns exactly one Encode message, so a message whose
            // row already left `pending` is a stale redelivery and is dropped here.
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if ($current->isEmpty() || $current->getAttribute('status') !== Base::STATUS_PENDING) {
                $outcome = 'duplicate';
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            // Compare-and-swap: concurrent coroutines can both see `pending`;
            // only one updateDocuments may transition the row. Claim before
            // downloading so a duplicate redelivery never fetches the source.
            $updated = $dbForProject->updateDocuments(
                'videos_renditions',
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
                // A sibling coroutine won the claim.
                $outcome = 'duplicate';
                $status = Base::STATUS_STARTED;
                return;
            }

            $rendition = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            $claimed = true;
            Span::add('rendition.name', (string) $rendition->getAttribute('name', ''));
            Span::add('rendition.output', $output);
            Span::add('rendition.codec', $codec);
            $stage = 'download';
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
            [$video, $inPath] = $this->prepareSource(
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

            // Silent reporters: failures reach the span through the exception,
            // so the library must not print its own copy to stdout.
            $probe = new FFprobe();
            $probe->setReporter(new Silent());
            $packager = new Packager(new FFmpeg(threads: self::THREADS), probe: $probe, reporter: new Silent());

            $stage = 'probe';
            if (!$packager->valid($inPath)) {
                throw new \Exception('Not a valid media file: ' . $inPath);
            }
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
            Span::add('encoder.threads', self::THREADS);

            Console::info(
                'Encoding video ' . $video->getId()
                . ' as ' . $rendition->getAttribute('name')
                . ' (' . $output . ')'
            );

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

            $target = match ($output) {
                Base::OUTPUT_DASH => (new Dash())->template(false)->timeline(false)->segment(self::SEGMENT_DURATION)->manifests(false),
                Base::OUTPUT_CMAF => (new Cmaf())->segment(self::SEGMENT_DURATION)->manifests(false),
                default => (new Hls())->segment(self::SEGMENT_DURATION)->manifests(false),
            };

            $lastProgress = -1;
            // Once the row leaves `started` (sweeper abort, error park, e2e seed),
            // never resume DB writes for this pack — even if status is set back to
            // `started` before ffmpeg exits.
            $halted = false;
            $lastStatusCheck = 0.0;
            $phaseStart = \microtime(true);
            $package = $packager
                ->open($inPath)
                ->format($format)
                ->add($representation)
                ->output($target)
                ->on(Packager::PROGRESS, function (mixed $progress) use ($dbForProject, $queueForRealtime, $project, $permissions, &$rendition, &$lastProgress, &$halted, &$lastStatusCheck, &$last) {
                    if (!$progress instanceof Progress) {
                        return;
                    }
                    $last = $progress;
                    if ($halted) {
                        return;
                    }

                    $now = \microtime(true);
                    $percentage = (int) \round($progress->percent);
                    $onWriteBoundary = $percentage % 3 === 0 && $percentage !== $lastProgress;
                    // Poll status ~every 500ms (and on write boundaries) so abort/
                    // error parks are noticed without a DB read on every ffmpeg tick.
                    if (!$onWriteBoundary && ($now - $lastStatusCheck) < 0.5) {
                        return;
                    }
                    $lastStatusCheck = $now;

                    $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
                    if ($current->isEmpty() || $current->getAttribute('status') !== Base::STATUS_STARTED) {
                        $halted = true;
                        return;
                    }

                    if (!$onWriteBoundary) {
                        return;
                    }
                    $lastProgress = $percentage;

                    $rendition = $dbForProject->updateDocument(
                        'videos_renditions',
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
            $this->phase($telemetry, VideoAction::Encode->value, 'encode', $phaseStart);
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

            // Maintenance (or an e2e park) may have moved the row out of
            // `started` while ffmpeg was still running — stop before ending.
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if (
                $halted
                || $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
            ) {
                $outcome = 'halted';
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            $path = $deviceForVideos->getPath($video->getId())
                . '/' . $rendition->getAttribute('name')
                . '-' . $rendition->getId() . '/';

            $stage = 'persist';
            $phaseStart = \microtime(true);
            // Drop any leftover segments from a previous attempt at the same id.
            // deleteDocuments paginates internally, so a long rendition's >1000
            // segment rows (a ~100-minute HLS ladder at 4s segments) are all
            // removed, not just the first APP_LIMIT_SUBQUERY page.
            $dbForProject->deleteDocuments('videos_renditions_segments', [
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

            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if (
                $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
            ) {
                $outcome = 'halted';
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            $rendition = $dbForProject->updateDocument(
                'videos_renditions',
                $rendition->getId(),
                new Document(\array_filter([
                    'status' => Base::STATUS_ENDED,
                    'endedAt' => DateTime::now(),
                    'metadata' => $metadata,
                    'targetDuration' => $targetDuration,
                ], fn ($value) => $value !== null))
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

            Console::info('Rendition ' . $rendition->getId() . ' conversion done');

            $stage = 'upload';
            $phaseStart = \microtime(true);
            $storageBytes = $this->uploadFiles(
                $package->files(),
                $path,
                $deviceForVideos,
                function (int $index) use ($dbForProject, $queueForRealtime, $project, $permissions, &$rendition, $path) {
                    if ($index !== 0) {
                        return;
                    }

                    $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
                    if (
                        $current->isEmpty()
                        || !\in_array($current->getAttribute('status'), [Base::STATUS_STARTED, Base::STATUS_ENDED], true)
                    ) {
                        return;
                    }

                    $rendition = $dbForProject->updateDocument(
                        'videos_renditions',
                        $rendition->getId(),
                        new Document([
                            'progress' => '100',
                            'status' => Base::STATUS_UPLOADING,
                            'path' => $path,
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
                }
            );

            $this->phase($telemetry, VideoAction::Encode->value, 'upload', $phaseStart);
            Span::add('rendition.size', $storageBytes);

            $stage = 'finalize';
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if (
                $current->isEmpty()
                || !\in_array($current->getAttribute('status'), [Base::STATUS_ENDED, Base::STATUS_UPLOADING], true)
            ) {
                $outcome = 'halted';
                $status = (string) $current->getAttribute('status', '');
                return;
            }

            $rendition = $dbForProject->updateDocument(
                'videos_renditions',
                $rendition->getId(),
                new Document([
                    'status' => Base::STATUS_READY,
                    'path' => $path,
                    'progress' => '100',
                    // Retained bytes. The usage event is skipped; StatsResources
                    // sums this column into the videos.storage gauge.
                    'size' => $storageBytes,
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
            $outcome = 'ready';
            $status = Base::STATUS_READY;
        } catch (\Throwable $th) {
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            // Do not overwrite aborted/error parks from maintenance or e2e seeding.
            // Pre-claim failures may only park while the row is still `pending` —
            // never clobber a sibling coroutine that already won the claim.
            $status = $current->isEmpty() ? '' : (string) $current->getAttribute('status', '');
            $mayPark = $claimed
                ? !\in_array($status, [Base::STATUS_ABORTED, Base::STATUS_ERROR], true)
                : $status === Base::STATUS_PENDING;

            if (!$current->isEmpty() && $mayPark) {
                $rendition = $dbForProject->updateDocument(
                    'videos_renditions',
                    $rendition->getId(),
                    new Document([
                        'status' => Base::STATUS_ERROR,
                        'endedAt' => DateTime::now(),
                        'progress' => $rendition->getAttribute('progress', '0'),
                        'metadata' => [
                            'code' => (string) $th->getCode(),
                            'message' => \substr($th->getMessage(), 0, 255),
                        ],
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
                $status = Base::STATUS_ERROR;
            }

            // The harness finishes the span with this exception, so the exporter
            // adds error.type/message/file/line; only the stage and ffmpeg detail
            // are added here.
            Span::add('error.stage', $stage);
            if ($th instanceof Runtime) {
                $this->runtime($telemetry, $th, 'ffmpeg', $stage);
            }

            throw $th;
        } finally {
            Span::add('rendition.outcome', $outcome);
            Span::add('rendition.status', $status);
            Span::add('rendition.claimed', $claimed);
            if ($outcome === 'halted') {
                Span::add('rendition.halt_status', $status);
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

            // Only a run that actually claimed the rendition (pending -> started)
            // is billable; duplicate messages and re-queued downloads are no-ops.
            if ($claimed) {
                try {
                    $computeMs = (int) \round((\microtime(true) - $startedAt) * 1000);
                    VideoUsage::publish(
                        $usage,
                        $videoMessage->video,
                        $rendition,
                        $project,
                        $publisherForUsage,
                        $storageBytes,
                        $computeMs
                    );
                } catch (\Throwable $th) {
                    Console::error('Failed to publish video usage: ' . $th->getMessage());
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
        $webvtt = (new SubripFile($srtPath))->convertTo('webvtt');
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
            throw new \Exception('Staged caption is empty');
        }

        if (\str_starts_with($prefix, "\xEF\xBB\xBF")) {
            $prefix = \substr($prefix, 3);
        }

        if (!\str_starts_with($prefix, 'WEBVTT')) {
            throw new \Exception('Staged caption is not WebVTT');
        }

        if (\strlen($prefix) > 6) {
            $next = $prefix[6];
            if ($next !== ' ' && $next !== "\t" && $next !== "\n" && $next !== "\r") {
                throw new \Exception('Staged caption is not WebVTT');
            }
        }
    }

    /**
     * Record the timeline cue file so its bytes join the videos.storage gauge.
     *
     * Sprite sheet rows are replaced on each timeline job. This row stays and
     * its size is replaced, so a rebuild does not stack the previous cue file.
     */
    private function persistTimelineVtt(Database $dbForProject, Document $video, string $path, int $bytes): void
    {
        $existing = $dbForProject->find('videos_timelines', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('name', ['timeline.vtt']),
            Query::limit(1),
        ]);

        if (empty($existing)) {
            $dbForProject->createDocument('videos_timelines', new Document([
                'videoId' => $video->getId(),
                'videoInternalId' => $video->getSequence(),
                'name' => 'timeline.vtt',
                'path' => $path,
                'size' => $bytes,
            ]));

            return;
        }

        $dbForProject->updateDocument('videos_timelines', $existing[0]->getId(), new Document([
            'path' => $path,
            'size' => $bytes,
        ]));
    }

    /**
     * Write a staged WebVTT file as the caption's single segment and mark ready.
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
        array $permissions
    ): Document {
        $dbForProject->deleteDocuments('videos_captions_segments', [
            Query::equal('captionInternalId', [$caption->getSequence()]),
        ]);

        $dir = $deviceForVideos->getPath($video->getId()) . '/captions/';
        $fileName = $caption->getId() . '.vtt';
        $fullPath = $dir . $fileName;
        // HLS EXT-X-TARGETDURATION must be a decimal-integer (seconds, rounded up).
        $duration = (string) \max(1, (int) \ceil(((int) $video->getAttribute('duration', 0)) / 1000));

        $dbForProject->createDocument('videos_captions_segments', new Document([
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

        $caption = $dbForProject->updateDocument(
            'videos_captions',
            $caption->getId(),
            new Document([
                'targetDuration' => $duration,
                'status' => Base::STATUS_READY,
                'path' => $fullPath,
                'size' => $bytes,
            ])
        );
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
            Console::warning('Videos worker: caption probe failed for ' . $video->getId() . ': ' . $th->getMessage());
            Span::add('captions.error.stage', 'probe');
            Span::add('warning.message', 'Caption probe failed: ' . $th->getMessage());
            if ($th instanceof Runtime) {
                $this->runtime($telemetry, $th, 'captions.error', 'probe');
            }
            return false;
        }

        $tracks = $info->tracks(Track::SUBTITLE);
        $streams = \array_map(
            static fn (Track $track) => $track->type . ':' . ($track->codec ?? 'unknown'),
            $info->tracks
        );
        Console::info(
            'Videos worker: found ' . \count($tracks)
            . ' caption stream(s) in source for video ' . $video->getId()
            . ' streams=[' . \implode(', ', $streams) . ']'
        );

        $textStreams = 0;
        foreach ($tracks as $track) {
            if (\in_array(\strtolower((string) ($track->codec ?? '')), self::TEXT_CAPTION_CODECS, true)) {
                $textStreams++;
            }
        }
        Span::add('captions.streams', \count($tracks));
        Span::add('captions.text_streams', $textStreams);

        $extracted = $telemetry->createCounter('worker.videos.captions.extracted', null, 'Embedded caption tracks by extraction result');
        $assignedDefault = false;
        $registered = 0;
        $skipped = 0;
        $failed = 0;
        $retry = false;
        $used = [];

        foreach ($tracks as $track) {
            $codec = \strtolower((string) ($track->codec ?? ''));
            $language = $track->language ?? 'und';

            Console::info(
                'Videos worker: caption stream index=' . $track->index
                . ' codec=' . ($track->codec ?? 'unknown')
                . ' language=' . $language
                . ' default=' . ($track->default ? 'yes' : 'no')
                . ' title=' . ($track->title ?? '')
                . ' video=' . $video->getId()
            );

            if ($codec === '' || !\in_array($codec, self::TEXT_CAPTION_CODECS, true)) {
                Console::warning(
                    'Videos worker: skipping non-text caption stream '
                    . $track->index . ' (' . ($track->codec ?? 'unknown') . ') on video '
                    . $video->getId()
                );
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
                Console::warning(
                    'Videos worker: failed extracting caption stream '
                    . $track->index . ' on video ' . $video->getId() . ': ' . $th->getMessage()
                );
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
                Console::warning(
                    'Videos worker: empty VTT for caption stream '
                    . $track->index . ' on video ' . $video->getId()
                );
                $this->record($extracted, 1, ['result' => 'skipped_empty', 'codec' => $codec]);
                $skipped++;
                continue;
            }

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

                    $caption = $dbForProject->createDocument('videos_captions', new Document([
                        '$id' => ID::unique(),
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
                Console::info(
                    'Videos worker: registered embedded caption ' . $caption->getId()
                    . ' code=' . $code
                    . ' name=' . $name
                    . ' default=' . ($caption->getAttribute('default', false) ? 'yes' : 'no')
                    . ' bytes=' . \filesize($vttPath)
                    . ' for video ' . $video->getId()
                );
            } catch (\Throwable $th) {
                Console::warning(
                    'Videos worker: failed registering embedded caption stream '
                    . $track->index . ' on video ' . $video->getId() . ': ' . $th->getMessage()
                );
                $this->record($extracted, 1, ['result' => 'failed', 'codec' => $codec]);
                $failed++;
                $retry = true;
                if ($failed === 1) {
                    Span::add('captions.error.stage', 'persist');
                    Span::add('captions.error.stream', $track->index);
                    Span::add('captions.error.codec', $codec);
                    Span::add('warning.message', 'Embedded caption registration failed: stream ' . $track->index . ' (' . $codec . '): ' . $th->getMessage());
                }
            }
        }

        // If no stream was flagged default, promote the first extracted track
        // when no other track already claims default.
        if (!$assignedDefault && !$this->hasDefaultCaption($dbForProject, $video)) {
            $embedded = $dbForProject->find('videos_captions', [
                Query::equal('videoInternalId', [$video->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($embedded as $caption) {
                if (!empty($caption->getAttribute('fileId', ''))) {
                    continue;
                }

                $caption = $dbForProject->updateDocument(
                    'videos_captions',
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
                Console::info(
                    'Videos worker: set default embedded caption ' . $caption->getId()
                    . ' on video ' . $video->getId()
                );
                break;
            }
        }

        Console::info(
            'Videos worker: embedded caption extract done for video ' . $video->getId()
            . ' registered=' . $registered
            . ' skipped=' . $skipped
            . ' failed=' . $failed
            . ' streams=' . \count($tracks)
            . ' retry=' . ($retry ? 'yes' : 'no')
        );
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
        $existing = $dbForProject->find('videos_captions', [
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
        $existing = $dbForProject->find('videos_captions', [
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
     * Download the storage file into the job directory, probe metadata once,
     * and extract embedded captions. A failed extract clears captionsExtracted
     * so a later job can retry.
     *
     * @param array{basePath: string, inDir: string, outDir: string} $workspace
     * @param array<string> $permissions
     * @return array{0: Document, 1: string}
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
        $file = $this->resolveFile(
            $dbForProject,
            $video->getAttribute('bucketId', ''),
            $video->getAttribute('fileId', '')
        );
        Span::add('source.name', (string) $file->getAttribute('name', ''));
        Span::add('source.path', (string) $file->getAttribute('path', ''));
        Span::add('source.mime', (string) $file->getAttribute('mimeType', ''));
        Span::add('source.size', (int) $file->getAttribute('sizeOriginal', 0));
        Span::add('source.encrypted', !empty($file->getAttribute('openSSLCipher')));
        Span::add('source.compression', (string) $file->getAttribute('algorithm', Compression::NONE));
        $inPath = $this->download($deviceForFiles, $file, $workspace['inDir']);
        $this->phase($telemetry, $action, 'download', $phaseStart);

        if ((int) $video->getAttribute('duration', 0) <= 0) {
            $stage = 'probe';
            $phaseStart = \microtime(true);
            $video = $this->probe($dbForProject, $video, $inPath);
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
                        $this->encoder(),
                        $queueForRealtime,
                        $project,
                        $permissions,
                        $telemetry
                    );
                } catch (\Throwable $th) {
                    Console::warning(
                        'Videos worker: embedded caption extract failed for '
                        . $video->getId() . ': ' . $th->getMessage()
                    );
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

        return [$video, $inPath];
    }

    private function cleanup(string $basePath): void
    {
        $root = \rtrim(APP_STORAGE_VIDEOS_TMP, '/') . '/';
        if ($basePath === '' || !\str_starts_with($basePath, $root)) {
            return;
        }

        $stdout = '';
        $stderr = '';
        $code = Console::execute('rm -rf ' . \escapeshellarg($basePath), '', $stdout, $stderr, 30);

        if ($code !== 0) {
            Console::error('Failed removing files from [' . $basePath . ']: ' . $stderr);
        }
    }

    private function resolveFile(Database $dbForProject, string $bucketId, string $fileId): Document
    {
        $bucket = $dbForProject->getDocument('buckets', $bucketId);

        if ($bucket->isEmpty()) {
            throw new \Exception('Source bucket not found: ' . $bucketId);
        }

        $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);

        if ($file->isEmpty()) {
            throw new \Exception('Source file not found: ' . $fileId);
        }

        return $file;
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
            throw new \Exception('Source file missing from storage: ' . $fullPath);
        }

        $hasEncryption = !empty($file->getAttribute('openSSLCipher'));
        $compression = $file->getAttribute('algorithm', Compression::NONE);
        $hasCompression = $compression !== Compression::NONE;
        $local = new Local('/');

        if ($hasEncryption || $hasCompression) {
            $data = (string) $deviceForFiles->read($fullPath);

            if ($hasEncryption) {
                $data = OpenSSL::decrypt(
                    $data,
                    $file->getAttribute('openSSLCipher'),
                    System::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                    0,
                    \hex2bin($file->getAttribute('openSSLIV')),
                    \hex2bin($file->getAttribute('openSSLTag'))
                );
            }

            if ($hasCompression) {
                $data = match ($compression) {
                    Compression::ZSTD => (new Zstd())->decompress($data),
                    Compression::GZIP => (new GZIP())->decompress($data),
                    default => $data,
                };
            }

            if (!$local->write($localPath, new Stream($data), $file->getAttribute('mimeType'))) {
                throw new \Exception('Unable to write decrypted source to ' . $localPath);
            }
        } elseif (!$deviceForFiles->copy($fullPath, $localPath, $local)) {
            throw new \Exception('Unable to transfer source to ' . $localPath);
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
        $info = ($encoder ?? $this->encoder())->probe($inPath);
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
        $targetDuration = null;
        $streams = [];

        foreach ($package->variants() as $index => $variant) {
            $streamId = $index;
            $streams[] = $this->streamMeta($variant, $streamId);

            foreach ($variant->segments as $segment) {
                $needsDuration = !$segment->init
                    && ($output === Base::OUTPUT_HLS || $output === Base::OUTPUT_CMAF);

                $dbForProject->createDocument('videos_renditions_segments', new Document(\array_filter([
                    'renditionId' => $rendition->getId(),
                    'renditionInternalId' => $rendition->getSequence(),
                    'streamId' => $streamId,
                    'fileName' => $segment->file,
                    'path' => $path,
                    'duration' => $needsDuration ? (string) $segment->duration : null,
                    'isInit' => $segment->init ? 1 : 0,
                ], fn ($value) => $value !== null)));
            }

            if ($targetDuration === null && $variant->target > 0) {
                $targetDuration = (string) (int) \ceil($variant->target);
            }
        }

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
                // SegmentList@duration is in timescale ticks — required for dash.js to
                // schedule SegmentURL entries when addressing is list-based.
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
     * Upload packaged artifacts to the videos device.
     *
     * @param  list<string>  $files
     * @param  callable(int):void|null  $onFile
     */
    private function uploadFiles(
        array $files,
        string $remoteDir,
        Device $deviceForVideos,
        ?callable $onFile = null
    ): int {
        $bytes = 0;
        $local = new Local('/');

        foreach ($files as $index => $localPath) {
            if (!\is_file($localPath)) {
                continue;
            }

            $data = $local->read($localPath);
            $bytes += $data->getSize() ?? (\filesize($localPath) ?: 0);
            $fileName = \basename($localPath);

            $deviceForVideos->write(
                $remoteDir . $fileName,
                $data,
                \mime_content_type($localPath) ?: 'application/octet-stream'
            );

            if ($onFile !== null) {
                $onFile($index);
            }
        }

        return $bytes;
    }

    private function encoder(): Encoder
    {
        // Silent reporters: failures reach the span through the exception, so
        // the library must not print its own copy to stdout.
        $probe = new FFprobe();
        $probe->setReporter(new Silent());

        return new Encoder(new FFmpeg(threads: self::THREADS), $probe, new Silent());
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
        Span::add($prefix . '.command', \substr(\implode(' ', $runtime->command()), 0, 2048));
        Span::add($prefix . '.stderr', \substr($runtime->output(), -4096));
        $this->record(
            $telemetry->createCounter('worker.videos.ffmpeg.exit', null, 'Failed ffmpeg and ffprobe runs by purpose and exit code'),
            1,
            ['purpose' => $purpose, 'exit_code' => (string) $runtime->getCode(), 'timed_out' => $timedOut ? 'true' : 'false']
        );
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
            $video->getRead()
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
}
