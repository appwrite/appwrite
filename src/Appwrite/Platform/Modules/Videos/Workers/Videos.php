<?php

namespace Appwrite\Platform\Modules\Videos\Workers;

use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Publisher\Video as VideoPublisher;
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
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Encoder;
use Utopia\Video\Format\X264;
use Utopia\Video\Info;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Package;
use Utopia\Video\Packager;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Tile;
use Utopia\Video\Track;
use Utopia\Video\Variant;

/**
 * Consumes the `videos` queue: sprite timelines, subtitle packaging and
 * rendition transcoding.
 *
 * The class name matches the module because this worker owns the module's
 * primary resource, as `Modules/Databases/Workers/Databases.php` does.
 */
class Videos extends Action
{
    /**
     * Soft text subtitle codecs that ffmpeg can convert to WebVTT.
     * Image-based streams (PGS, VobSub, …) are skipped.
     *
     * @var list<string>
     */
    private const TEXT_SUBTITLE_CODECS = [
        'subrip',
        'srt',
        'webvtt',
        'mov_text',
        'text',
        'ass',
        'ssa',
    ];

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
            ->inject('publisherForVideos')
            ->inject('log')
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
        VideoPublisher $publisherForVideos,
        Log $log
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

        $log->addTag('projectId', $project->getId());
        $log->addTag('videoId', $videoMessage->video->getId());
        $log->addTag('action', $action->value);

        match ($action) {
            VideoAction::Download => $this->downloadSource(
                $dbForProject,
                $deviceForFiles,
                $deviceForVideos,
                $queueForRealtime,
                $project,
                $videoMessage
            ),
            VideoAction::Timeline => $this->timeline(
                $dbForProject,
                $deviceForVideos,
                $queueForRealtime,
                $project,
                $videoMessage
            ),
            VideoAction::Subtitle => $this->subtitle(
                $dbForProject,
                $deviceForFiles,
                $deviceForVideos,
                $videoMessage
            ),
            VideoAction::Encode => $this->encode(
                $dbForProject,
                $deviceForVideos,
                $queueForRealtime,
                $usage,
                $publisherForUsage,
                $publisherForVideos,
                $project,
                $videoMessage
            ),
        };
    }

    /**
     * Fetch the source onto videos-tmp, probe it, extract embedded text
     * subtitles, and mark the video ready. Timeline and rendition jobs are
     * client-enqueued; this job does not fan out.
     */
    private function downloadSource(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Document $project,
        VideoMessage $videoMessage
    ): void {
        $projectId = $videoMessage->project->getId();
        $videoId = $videoMessage->video->getId();
        $root = $this->getTmpPath($projectId, $videoId);

        if (!\is_dir($root) && !\mkdir($root, 0755, true) && !\is_dir($root)) {
            throw new \Exception('Failed to create videos-tmp directory');
        }

        try {
            $video = $dbForProject->getDocument('videos', $videoId);
            if ($video->isEmpty()) {
                throw new \Exception('Video not found: ' . $videoId);
            }

            $permissions = $this->sourceReadPermissions($dbForProject, $project, $video);
            $file = $this->resolveFile(
                $dbForProject,
                $video->getAttribute('bucketId', ''),
                $video->getAttribute('fileId', '')
            );
            $sourcePath = $this->sourcePath($projectId, $videoId);
            $fetched = !$this->sourceReady($sourcePath, $video)
                || $video->getAttribute('status') !== Base::SOURCE_READY;

            if ($fetched) {
                $this->fetchSource(
                    $dbForProject,
                    $deviceForFiles,
                    $queueForRealtime,
                    $project,
                    $video,
                    $file,
                    $sourcePath,
                    $permissions
                );
                $video = $this->probe($dbForProject, $video, $file, $sourcePath);
            }

            if (!$video->getAttribute('subtitlesExtracted', false)) {
                $workspace = $this->jobWorkspace($projectId, $videoId);
                try {
                    $this->extractEmbeddedSubtitles(
                        $dbForProject,
                        $deviceForVideos,
                        $video,
                        $sourcePath,
                        $workspace['outDir'],
                        $this->encoder()
                    );
                    $video = $dbForProject->updateDocument(
                        'videos',
                        $video->getId(),
                        new Document(['subtitlesExtracted' => true])
                    );
                } catch (\Throwable $th) {
                    Console::warning(
                        'Videos worker: embedded subtitle extract failed for '
                        . $videoId . ': ' . $th->getMessage()
                    );
                } finally {
                    $this->cleanup($workspace['basePath']);
                }
            }

            $this->setVideoStatus(
                $dbForProject,
                $queueForRealtime,
                $project,
                $video,
                Base::SOURCE_READY,
                $permissions,
                (int) $video->getAttribute('chunksTotal', 1),
                (int) $video->getAttribute('chunksTotal', 1)
            );
        } catch (\Throwable $th) {
            $video = $dbForProject->getDocument('videos', $videoId);
            if (!$video->isEmpty()) {
                $this->setVideoStatus(
                    $dbForProject,
                    $queueForRealtime,
                    $project,
                    $video,
                    Base::SOURCE_ERROR,
                    $this->sourceReadPermissions($dbForProject, $project, $video)
                );
            }

            throw $th;
        }
    }

    /**
     * Probe the source, tile sprite sheets and emit a relative WebVTT timeline.
     */
    private function timeline(
        Database $dbForProject,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Document $project,
        VideoMessage $videoMessage
    ): void {
        $video = $videoMessage->video;
        $projectId = $videoMessage->project->getId();
        $workspace = $this->jobWorkspace($projectId, $video->getId());

        try {
            Console::info('Videos worker: timeline started for video ' . $video->getId());
            $inPath = $this->assertSource($dbForProject, $queueForRealtime, $project, $videoMessage);

            $encoder = $this->encoder();

            // Prefer dimensions over bitrate: many containers (VBR MKV, some DivX)
            // report width/height but leave bitrate as 0, and empty(0) is true in PHP.
            $width = (int) $video->getAttribute('width', 0);
            $height = (int) $video->getAttribute('height', 0);

            if ($width <= 0 || $height <= 0) {
                Console::warning('Videos worker: source has no video track; skipping timeline for ' . $video->getId());
                return;
            }

            // Wipe previous sprites so a source-file update can regenerate cleanly
            // against the UNIQUE (videoId, type, name) index.
            $existing = $dbForProject->find('videos_previews', [
                Query::equal('videoInternalId', [$video->getSequence()]),
                Query::equal('type', ['sprite']),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($existing as $preview) {
                $path = $preview->getAttribute('path', '');
                if (!empty($path) && $deviceForVideos->exists($path)) {
                    try {
                        $deviceForVideos->delete($path);
                    } catch (\Throwable) {
                        // Best-effort; the DB row is the source of truth for readiness.
                    }
                }
                $dbForProject->deleteDocument('videos_previews', $preview->getId());
            }

            // Appwrite rewrites sheet URLs to preview endpoints, so skip the
            // library-written VTT and render our own from cues().
            Console::info('Videos worker: encoder=' . $encoder->getName() . ' tiling sprites for video ' . $video->getId());
            $sheet = $encoder->tile(
                $inPath,
                \rtrim($workspace['outDir'], '/'),
                (new Tile())->vtt(false)
            );

            $timelineDir = $deviceForVideos->getPath($video->getId()) . '/timeline/';
            $urls = [];

            foreach ($sheet->images() as $localFile) {
                $fileName = \basename($localFile);
                $fullPath = $timelineDir . $fileName;

                $preview = $dbForProject->createDocument('videos_previews', new Document([
                    'videoId' => $video->getId(),
                    'videoInternalId' => $video->getSequence(),
                    'type' => 'sprite',
                    'name' => $fileName,
                    'path' => $fullPath,
                ]));

                $deviceForVideos->write(
                    $fullPath,
                    (new Local('/'))->read($localFile),
                    'image/jpeg'
                );

                // Relative to /v1/videos/{videoId}/timeline so the player resolves
                // to /v1/videos/{videoId}/previews/{previewId}.
                $urls[$fileName] = 'previews/' . $preview->getId();
            }

            if (!empty($urls)) {
                $vtt = $sheet->render(fn (string $file): string => $urls[$file] ?? $file);
                $vttPath = $deviceForVideos->getPath($video->getId() . '/timeline') . '/timeline.vtt';
                $deviceForVideos->write($vttPath, new Stream($vtt), 'text/vtt');
                Console::info('Uploaded timeline vtt for video ' . $video->getId());
            }
        } finally {
            $this->cleanup($workspace['basePath']);
            $this->tryRelease($dbForProject, $queueForRealtime, $project, $projectId, $video->getId());
        }
    }

    /**
     * Normalise a subtitle to WebVTT, write a segment row and upload the file.
     */
    private function subtitle(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        VideoMessage $videoMessage
    ): void {
        $subtitle = $videoMessage->subtitle;

        if ($subtitle === null || $subtitle->isEmpty()) {
            throw new \Exception('Missing subtitle in payload');
        }

        // Re-fetch rather than trust the queue snapshot: a subtitle created before
        // the source was probed carries duration 0, which would bake
        // targetDuration "0.0" into the subtitle playlist.
        $video = $dbForProject->getDocument('videos', $videoMessage->video->getId());
        if ($video->isEmpty()) {
            $video = $videoMessage->video;
        }
        $workspace = $this->workspace($videoMessage->project->getId(), $video->getId());

        try {
            $subtitle = $dbForProject->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                new Document([
                    'status' => Base::STATUS_STARTED,
                ])
            );

            $file = $this->resolveFile(
                $dbForProject,
                $subtitle->getAttribute('bucketId', ''),
                $subtitle->getAttribute('fileId', '')
            );
            $downloaded = $this->download($deviceForFiles, $file, $workspace['inDir']);
            $ext = \strtolower(\pathinfo($downloaded, PATHINFO_EXTENSION));
            $subtitlePath = $workspace['inDir'] . $subtitle->getId() . '.vtt';

            if ($ext === 'srt') {
                $this->subripToWebvtt($downloaded, $subtitlePath);
            } elseif (\in_array($ext, ['vtt', 'webvtt'], true)) {
                if (!\copy($downloaded, $subtitlePath)) {
                    throw new \Exception('Failed to stage WebVTT subtitle');
                }
            } else {
                // text/plain and application/x-subrip without a .srt extension: try
                // Subrip parsing, then fall back to a straight copy.
                try {
                    $this->subripToWebvtt($downloaded, $subtitlePath);
                } catch (\Throwable) {
                    if (!\copy($downloaded, $subtitlePath)) {
                        throw new \Exception('Failed to stage subtitle as WebVTT');
                    }
                }
            }

            $this->persistSubtitleVtt($dbForProject, $deviceForVideos, $video, $subtitle, $subtitlePath);
        } catch (\Throwable $th) {
            $dbForProject->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                new Document([
                    'status' => Base::STATUS_ERROR,
                ])
            );

            throw $th;
        } finally {
            $this->cleanup($workspace['basePath']);
        }
    }

    /**
     * Transcode and package a rendition into HLS or DASH.
     *
     * The rendition row is created by the HTTP endpoint with status `waiting`.
     */
    private function encode(
        Database $dbForProject,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Context $usage,
        UsagePublisher $publisherForUsage,
        VideoPublisher $publisherForVideos,
        Document $project,
        VideoMessage $videoMessage
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
        // Created only after this run wins the waiting→started claim. Workspace
        // paths are keyed by rendition id, so a stale redelivery that mkdir+rm's
        // the same tree would delete segments out from under a live ffmpeg.
        $workspace = null;
        $startedAt = \microtime(true);
        $storageBytes = 0;
        $output = $videoMessage->output !== ''
            ? $videoMessage->output
            : (string) $rendition->getAttribute('output', Base::OUTPUT_HLS);
        $permissions = $this->sourceReadPermissions($dbForProject, $project, $videoMessage->video);
        $claimed = false;

        try {
            // Every rendition owns exactly one Encode message, so a message whose
            // row already left `waiting` is a stale redelivery and is dropped here.
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if ($current->isEmpty() || $current->getAttribute('status') !== Base::STATUS_WAITING) {
                return;
            }

            $inPath = $this->assertSource($dbForProject, $queueForRealtime, $project, $videoMessage);

            $video = $dbForProject->getDocument('videos', $videoId);
            $ffmpeg = new FFmpeg(threads: 4);
            $packager = new Packager($ffmpeg);

            if (!$packager->valid($inPath)) {
                throw new \Exception('Not a valid media file: ' . $inPath);
            }

            // Compare-and-swap: concurrent coroutines can both see `waiting`;
            // only one updateDocuments may transition the row.
            $updated = $dbForProject->updateDocuments(
                'videos_renditions',
                new Document([
                    'startedAt' => DateTime::now(),
                    'status' => Base::STATUS_STARTED,
                    'progress' => '0',
                ]),
                [
                    Query::equal('$id', [$rendition->getId()]),
                    Query::equal('status', [Base::STATUS_WAITING]),
                ]
            );
            if ($updated === 0) {
                return;
            }

            $rendition = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            $claimed = true;
            $this->notify($queueForRealtime, $project, $rendition, 'update', $permissions);

            $workspace = $this->jobWorkspace($projectId, $videoId, $rendition->getId());

            $representation = new Representation(
                width: (int) $profile->getAttribute('width'),
                height: (int) $profile->getAttribute('height'),
                video: (int) $profile->getAttribute('videoBitRate'),
                audio: \max(1, (int) $profile->getAttribute('audioBitRate')),
            );

            Console::info(
                'Encoding video ' . $video->getId()
                . ' as ' . $rendition->getAttribute('name')
                . ' (' . $output . ')'
            );

            $format = (new X264())
                ->crf(22)
                ->bframes(3)
                ->keyframe(2.0)
                ->params(['-dn', '-sn']);

            $target = match ($output) {
                Base::OUTPUT_DASH => (new Dash())->template(false)->timeline(false)->segment(6)->manifests(false),
                Base::OUTPUT_CMAF => (new Cmaf())->segment(6)->manifests(false),
                default => (new Hls())->segment(6)->manifests(false),
            };

            Console::info(
                'Videos worker: packager=' . $packager->getName()
                . ' output=' . $output
                . ' video=' . $video->getId()
                . ' rendition=' . $rendition->getId()
            );

            $lastProgress = -1;
            // Once the row leaves `started` (sweeper abort, error park, e2e seed),
            // never resume DB writes for this pack — even if status is set back to
            // `started` before ffmpeg exits.
            $halted = false;
            $lastStatusCheck = 0.0;
            $package = $packager
                ->open($inPath)
                ->format($format)
                ->add($representation)
                ->output($target)
                ->on(Packager::PROGRESS, function (mixed $progress) use ($dbForProject, $queueForRealtime, $project, $permissions, &$rendition, &$lastProgress, &$halted, &$lastStatusCheck) {
                    if ($halted || !$progress instanceof Progress) {
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
                    $this->notify($queueForRealtime, $project, $rendition, 'update', $permissions);
                })
                ->on(Packager::LOG, function (mixed $line) {
                    if (\is_string($line) && \trim($line) !== '') {
                        Console::info('Videos worker: packager: ' . \trim($line));
                    }
                })
                ->pack(\rtrim($workspace['outDir'], '/'));

            // Maintenance (or an e2e park) may have moved the row out of
            // `started` while ffmpeg was still running — stop before ending.
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if (
                $halted
                || $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
            ) {
                return;
            }

            $path = $deviceForVideos->getPath($video->getId())
                . '/' . $rendition->getAttribute('name')
                . '-' . $rendition->getId() . '/';

            // Drop any leftover segments from a previous attempt at the same id.
            // deleteDocuments paginates internally, so a long rendition's >1000
            // segment rows (a ~100-minute HLS ladder at 6s segments) are all
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

            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if (
                $current->isEmpty()
                || $current->getAttribute('status') !== Base::STATUS_STARTED
            ) {
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
            $this->notify($queueForRealtime, $project, $rendition, 'update', $permissions);

            Console::info('Rendition ' . $rendition->getId() . ' conversion done');

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
                    $this->notify($queueForRealtime, $project, $rendition, 'update', $permissions);
                }
            );

            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if (
                $current->isEmpty()
                || !\in_array($current->getAttribute('status'), [Base::STATUS_ENDED, Base::STATUS_UPLOADING], true)
            ) {
                return;
            }

            $rendition = $dbForProject->updateDocument(
                'videos_renditions',
                $rendition->getId(),
                new Document([
                    'status' => Base::STATUS_READY,
                    'path' => $path,
                    'progress' => '100',
                ])
            );
            $this->notify($queueForRealtime, $project, $rendition, 'update', $permissions);
        } catch (\Throwable $th) {
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            // Do not overwrite aborted/error parks from maintenance or e2e seeding.
            // Pre-claim failures may only park while the row is still `waiting` —
            // never clobber a sibling coroutine that already won the claim.
            $status = $current->isEmpty() ? '' : (string) $current->getAttribute('status', '');
            $mayPark = $claimed
                ? !\in_array($status, [Base::STATUS_ABORTED, Base::STATUS_ERROR], true)
                : $status === Base::STATUS_WAITING;

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
                $this->notify($queueForRealtime, $project, $rendition, 'update', $permissions);
            }

            Console::error(
                'Error encoding video ' . $videoMessage->video->getId() . PHP_EOL
                . 'Message: ' . $th->getMessage() . PHP_EOL
                . 'File: ' . $th->getFile() . PHP_EOL
                . 'Line: ' . $th->getLine()
            );

            throw $th;
        } finally {
            // Only a run that actually claimed the rendition (waiting -> started)
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
            $this->tryRelease($dbForProject, $queueForRealtime, $project, $projectId, $videoId);
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
     * Write a staged WebVTT file as the subtitle's single segment and mark ready.
     */
    private function persistSubtitleVtt(
        Database $dbForProject,
        Device $deviceForVideos,
        Document $video,
        Document $subtitle,
        string $vttPath
    ): Document {
        $dbForProject->deleteDocuments('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
        ]);

        $dir = $deviceForVideos->getPath($video->getId()) . '/subtitles/';
        $fileName = $subtitle->getId() . '.vtt';
        $fullPath = $dir . $fileName;
        // HLS EXT-X-TARGETDURATION must be a decimal-integer (seconds, rounded up).
        $duration = (string) \max(1, (int) \ceil(((int) $video->getAttribute('duration', 0)) / 1000));

        $dbForProject->createDocument('videos_subtitles_segments', new Document([
            'subtitleId' => $subtitle->getId(),
            'subtitleInternalId' => $subtitle->getSequence(),
            'fileName' => $fileName,
            'path' => $dir,
            'duration' => $duration,
        ]));

        Console::info('Uploading ' . $fileName);
        $deviceForVideos->write(
            $fullPath,
            (new Local('/'))->read($vttPath),
            'text/vtt'
        );

        return $dbForProject->updateDocument(
            'videos_subtitles',
            $subtitle->getId(),
            new Document([
                'targetDuration' => $duration,
                'status' => Base::STATUS_READY,
                'path' => $fullPath,
            ])
        );
    }

    /**
     * Replace auto-extracted text subtitle tracks from the source container.
     *
     * Uploaded tracks (non-empty fileId) always win for a given language code.
     * Image-based streams are skipped. One failed track does not fail the timeline.
     */
    private function extractEmbeddedSubtitles(
        Database $dbForProject,
        Device $deviceForVideos,
        Document $video,
        string $inPath,
        string $outDir,
        Encoder $encoder
    ): void {
        Console::info('Videos worker: extracting embedded subtitles for video ' . $video->getId());

        $existing = $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $uploadedCodes = [];
        $hasDefault = false;

        foreach ($existing as $subtitle) {
            if (!empty($subtitle->getAttribute('fileId', ''))) {
                $uploadedCodes[$subtitle->getAttribute('code', '')] = true;
            }
            if ($subtitle->getAttribute('default', false)) {
                $hasDefault = true;
            }
        }

        if (!empty($uploadedCodes)) {
            Console::info(
                'Videos worker: upload-owned languages on video ' . $video->getId()
                . ': ' . \implode(', ', \array_keys($uploadedCodes))
            );
        }

        try {
            $info = $encoder->probe($inPath);
        } catch (\Throwable $th) {
            Console::warning('Videos worker: subtitle probe failed for ' . $video->getId() . ': ' . $th->getMessage());
            return;
        }

        $tracks = $info->tracks(Track::SUBTITLE);
        $streams = \array_map(
            static fn (Track $track) => $track->type . ':' . ($track->codec ?? 'unknown'),
            $info->tracks
        );
        Console::info(
            'Videos worker: found ' . \count($tracks)
            . ' subtitle stream(s) in source for video ' . $video->getId()
            . ' streams=[' . \implode(', ', $streams) . ']'
        );

        $assignedDefault = false;
        $registered = 0;
        $skipped = 0;

        foreach ($tracks as $track) {
            $codec = \strtolower((string) ($track->codec ?? ''));
            $language = $track->language ?? 'und';

            Console::info(
                'Videos worker: subtitle stream index=' . $track->index
                . ' codec=' . ($track->codec ?? 'unknown')
                . ' language=' . $language
                . ' default=' . ($track->default ? 'yes' : 'no')
                . ' title=' . ($track->title ?? '')
                . ' video=' . $video->getId()
            );

            if ($codec === '' || !\in_array($codec, self::TEXT_SUBTITLE_CODECS, true)) {
                Console::warning(
                    'Videos worker: skipping non-text subtitle stream '
                    . $track->index . ' (' . ($track->codec ?? 'unknown') . ') on video '
                    . $video->getId()
                );
                $skipped++;
                continue;
            }

            $code = $this->subtitleLanguageCode($track->language);

            if (isset($uploadedCodes[$code])) {
                Console::info(
                    'Videos worker: skipping embedded ' . $code
                    . ' — upload already owns that language on video ' . $video->getId()
                );
                $skipped++;
                continue;
            }

            $vttPath = \rtrim($outDir, '/') . '/sub_' . $track->index . '.vtt';

            try {
                Console::info(
                    'Videos worker: ffmpeg extract map 0:' . $track->index
                    . ' -> webvtt for video ' . $video->getId()
                );
                $this->ffmpegExtractSubtitle($inPath, $track->index, $vttPath);
            } catch (\Throwable $th) {
                Console::warning(
                    'Videos worker: failed extracting subtitle stream '
                    . $track->index . ' on video ' . $video->getId() . ': ' . $th->getMessage()
                );
                $skipped++;
                continue;
            }

            if (!\is_file($vttPath) || \filesize($vttPath) === 0) {
                Console::warning(
                    'Videos worker: empty VTT for subtitle stream '
                    . $track->index . ' on video ' . $video->getId()
                );
                $skipped++;
                continue;
            }

            $name = $this->sanitizeMeta(
                $track->title
                ?? ($track->language !== null && $track->language !== '' ? $track->language : null)
                ?? ('Track ' . $track->index)
            );

            $isDefault = false;
            if (!$hasDefault && !$assignedDefault) {
                if ($track->default) {
                    $isDefault = true;
                    $assignedDefault = true;
                }
            }

            try {
                $subtitle = $dbForProject->createDocument('videos_subtitles', new Document([
                    '$id' => ID::unique(),
                    'videoId' => $video->getId(),
                    'videoInternalId' => $video->getSequence(),
                    'name' => $name,
                    'code' => $code,
                    'default' => $isDefault,
                    'status' => Base::STATUS_STARTED,
                ]));

                $this->persistSubtitleVtt($dbForProject, $deviceForVideos, $video, $subtitle, $vttPath);
                $registered++;
                Console::info(
                    'Videos worker: registered embedded subtitle ' . $subtitle->getId()
                    . ' code=' . $code
                    . ' name=' . $name
                    . ' default=' . ($isDefault ? 'yes' : 'no')
                    . ' bytes=' . \filesize($vttPath)
                    . ' for video ' . $video->getId()
                );
            } catch (\Throwable $th) {
                Console::warning(
                    'Videos worker: failed registering embedded subtitle stream '
                    . $track->index . ' on video ' . $video->getId() . ': ' . $th->getMessage()
                );
                $skipped++;
            }
        }

        // If no stream was flagged default, promote the first extracted track
        // when no upload already claims default.
        if (!$hasDefault && !$assignedDefault) {
            $embedded = $dbForProject->find('videos_subtitles', [
                Query::equal('videoInternalId', [$video->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($embedded as $subtitle) {
                if (!empty($subtitle->getAttribute('fileId', ''))) {
                    continue;
                }

                $dbForProject->updateDocument(
                    'videos_subtitles',
                    $subtitle->getId(),
                    new Document(['default' => true])
                );
                Console::info(
                    'Videos worker: set default embedded subtitle ' . $subtitle->getId()
                    . ' on video ' . $video->getId()
                );
                break;
            }
        }

        Console::info(
            'Videos worker: embedded subtitle extract done for video ' . $video->getId()
            . ' registered=' . $registered
            . ' skipped=' . $skipped
            . ' streams=' . \count($tracks)
        );
    }

    /**
     * Map a container language tag to an ISO 639-2 code2 used by the API.
     */
    private function subtitleLanguageCode(?string $language): string
    {
        if ($language === null || $language === '') {
            return 'und';
        }

        $tag = \strtolower(\str_replace('_', '-', \trim($language)));
        $primary = \explode('-', $tag)[0];

        foreach (Config::getParam('locale-languages') as $entry) {
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
     * Extract one subtitle stream to WebVTT with the container ffmpeg binary.
     */
    private function ffmpegExtractSubtitle(string $inPath, int $streamIndex, string $outPath): void
    {
        $stdout = '';
        $stderr = '';
        $command = 'ffmpeg -y -i ' . \escapeshellarg($inPath)
            . ' -map 0:' . $streamIndex
            . ' -c:s webvtt '
            . \escapeshellarg($outPath);

        Console::info('Videos worker: ffmpeg command: ' . $command);

        $code = Console::execute($command, '', $stdout, $stderr, 60);

        if ($code !== 0) {
            throw new \Exception(\trim($stderr) !== '' ? \trim($stderr) : 'ffmpeg exit ' . $code);
        }
    }

    private function getTmpPath(string $projectId, string $videoId): string
    {
        return Base::tmpPath($projectId, $videoId);
    }

    private function sourcePath(string $projectId, string $videoId): string
    {
        return Base::tmpSourcePath($projectId, $videoId);
    }

    /**
     * Per-job output directory under `{videoId}/jobs/{jobId}/out/`.
     *
     * Encode passes the rendition id so CleanStaleVideosResources can release
     * that workspace alone. Call only after this run has claimed the rendition
     * (waiting→started): cleanup is keyed by the same id, and a no-op redelivery
     * must not mkdir+rm the tree an in-flight encode is writing. Timeline /
     * subtitle extract omit `$jobId` and get a uniqid — those runs are not
     * aborted via the rendition sweeper.
     *
     * @return array{basePath: string, outDir: string}
     */
    private function jobWorkspace(string $projectId, string $videoId, ?string $jobId = null): array
    {
        $basePath = Base::tmpJobPath($projectId, $videoId, $jobId ?? \uniqid('', true));
        $outDir = $basePath . '/out/';

        if (!\mkdir($outDir, 0755, true) && !\is_dir($outDir)) {
            throw new \Exception('Failed to create temp output directory');
        }

        return [
            'basePath' => $basePath,
            'outDir' => $outDir,
        ];
    }

    private function sourceReady(string $sourcePath, Document $video): bool
    {
        return Base::sourceMatches($sourcePath, (int) $video->getAttribute('size', 0));
    }

    private function assertSource(
        Database $dbForProject,
        Realtime $queueForRealtime,
        Document $project,
        VideoMessage $videoMessage
    ): string {
        $video = $dbForProject->getDocument('videos', $videoMessage->video->getId());
        $path = $this->sourcePath($videoMessage->project->getId(), $video->getId());

        if ($this->sourceReady($path, $video)) {
            return $path;
        }

        // Disk is the truth: the row claims a live working copy but the file is
        // gone (crash, manual cleanup). Correct the status so createSource can
        // materialise the source again instead of refusing on `ready`.
        if (!$video->isEmpty() && $video->getAttribute('status') === Base::SOURCE_READY) {
            $this->setVideoStatus(
                $dbForProject,
                $queueForRealtime,
                $project,
                $video,
                Base::SOURCE_REMOVED,
                $this->sourceReadPermissions($dbForProject, $project, $video)
            );
        }

        throw new \Exception('Source missing or incomplete for ' . $video->getId());
    }

    /**
     * @param array<string> $permissions
     */
    private function fetchSource(
        Database $dbForProject,
        Device $deviceForFiles,
        Realtime $queueForRealtime,
        Document $project,
        Document $video,
        Document $file,
        string $sourcePath,
        array $permissions
    ): void {
        $fullPath = $file->getAttribute('path', '');

        if (!$deviceForFiles->exists($fullPath)) {
            throw new \Exception('Source file missing from storage: ' . $fullPath);
        }

        $storedSize = $deviceForFiles->getFileSize($fullPath);
        $chunks = Base::chunkCount($storedSize);
        $partPath = $sourcePath . '.' . \uniqid('', true) . '.part';

        $video = $this->setVideoStatus(
            $dbForProject,
            $queueForRealtime,
            $project,
            $video,
            Base::SOURCE_DOWNLOADING,
            $permissions,
            $chunks,
            0
        );

        Console::info('Downloading source for video ' . $video->getId() . ' in ' . $chunks . ' chunk(s)');

        $handle = \fopen($partPath, 'wb');
        if ($handle === false) {
            throw new \Exception('Unable to open source part file');
        }

        try {
            $chunkSize = APP_LIMIT_UPLOAD_CHUNK_SIZE;
            // Cap progress writes at ~100 regardless of size (a 5 GB source is 1000
            // chunks); always report the final chunk. For small sources step is 1,
            // so every chunk is still reported.
            $step = \max(1, \intdiv($chunks, 100));
            for ($chunk = 1; $chunk <= $chunks; $chunk++) {
                $offset = ($chunk - 1) * $chunkSize;
                $length = (int) \min($chunkSize, $storedSize - $offset);
                $data = (string) $deviceForFiles->read($fullPath, $offset, $length);
                if (\fwrite($handle, $data) === false) {
                    throw new \Exception('Unable to write source chunk ' . $chunk);
                }

                if ($chunk % $step === 0 || $chunk === $chunks) {
                    $this->setVideoStatus(
                        $dbForProject,
                        $queueForRealtime,
                        $project,
                        $video,
                        Base::SOURCE_DOWNLOADING,
                        $permissions,
                        $chunks,
                        $chunk
                    );
                }
            }
        } finally {
            \fclose($handle);
        }

        $hasEncryption = !empty($file->getAttribute('openSSLCipher'));
        $compression = $file->getAttribute('algorithm', Compression::NONE);
        $hasCompression = $compression !== Compression::NONE;

        if ($hasEncryption || $hasCompression) {
            $data = (string) \file_get_contents($partPath);

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

            $decodedPath = $sourcePath . '.' . \uniqid('', true) . '.decoded';
            if (\file_put_contents($decodedPath, $data) === false) {
                throw new \Exception('Unable to write decrypted source');
            }
            \unlink($partPath);
            if (!\rename($decodedPath, $sourcePath)) {
                \unlink($decodedPath);
                throw new \Exception('Unable to finalise source download');
            }
        } elseif (!\rename($partPath, $sourcePath)) {
            throw new \Exception('Unable to finalise source download');
        }

        $expected = (int) $video->getAttribute('size', 0);
        if (!Base::sourceMatches($sourcePath, $expected)) {
            // sourceMatches already cleared the path's stat cache.
            $actual = \is_file($sourcePath) ? (int) \filesize($sourcePath) : 0;
            throw new \Exception(
                'Source size mismatch for video ' . $video->getId()
                . ': expected ' . $expected . ', got ' . $actual
            );
        }
    }

    private function tryRelease(
        Database $dbForProject,
        Realtime $queueForRealtime,
        Document $project,
        string $projectId,
        string $videoId
    ): void {
        $video = $dbForProject->getDocument('videos', $videoId);
        if ($video->isEmpty()) {
            return;
        }

        if ($video->getAttribute('status') === Base::SOURCE_DOWNLOADING) {
            return;
        }

        $inFlight = $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [
                Base::STATUS_WAITING,
                Base::STATUS_STARTED,
                Base::STATUS_ENDED,
                Base::STATUS_UPLOADING,
            ]),
            Query::limit(1),
        ]);

        // Another encode is still using the tmp source — skip the jobs glob.
        if (!empty($inFlight)) {
            return;
        }

        $jobs = $this->getTmpPath($projectId, $videoId) . '/jobs';
        $jobsRemain = \is_dir($jobs) && !empty(\glob($jobs . '/*', GLOB_ONLYDIR));

        if ($jobsRemain) {
            return;
        }

        // Rendition create may have inserted `waiting` after the first find —
        // re-check immediately before unlinking so we do not drop the source
        // under a new claim.
        $inFlight = $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [
                Base::STATUS_WAITING,
                Base::STATUS_STARTED,
                Base::STATUS_ENDED,
                Base::STATUS_UPLOADING,
            ]),
            Query::limit(1),
        ]);
        if (!empty($inFlight)) {
            return;
        }

        $sourcePath = $this->sourcePath($projectId, $videoId);
        foreach (\glob($sourcePath . '*') ?: [] as $path) {
            if (\is_file($path)) {
                \unlink($path);
                Console::info('Released source [' . $path . ']');
            }
        }

        $this->setVideoStatus(
            $dbForProject,
            $queueForRealtime,
            $project,
            $video,
            Base::SOURCE_REMOVED,
            $this->sourceReadPermissions($dbForProject, $project, $video)
        );
    }

    /**
     * @param array<string> $permissions
     */
    private function setVideoStatus(
        Database $dbForProject,
        Realtime $queueForRealtime,
        Document $project,
        Document $video,
        string $status,
        array $permissions,
        ?int $chunksTotal = null,
        ?int $chunksUploaded = null
    ): Document {
        // Maintenance may have aborted a stuck download. Late ready/error from
        // that same worker must not overwrite aborted; a fresh download
        // (aborted → downloading) must still be allowed for client retry.
        $current = $dbForProject->getDocument('videos', $video->getId());
        if (
            !$current->isEmpty()
            && $current->getAttribute('status') === Base::SOURCE_ABORTED
            && \in_array($status, [Base::SOURCE_READY, Base::SOURCE_ERROR], true)
        ) {
            return $current;
        }

        $data = ['status' => $status];
        if ($chunksTotal !== null) {
            $data['chunksTotal'] = $chunksTotal;
        }
        if ($chunksUploaded !== null) {
            $data['chunksUploaded'] = $chunksUploaded;
        }

        $video = $dbForProject->updateDocument('videos', $video->getId(), new Document($data));
        $this->notifyVideo($queueForRealtime, $project, $video, $permissions);

        return $video;
    }

    /**
     * @param array<string> $permissions
     */
    private function notifyVideo(Realtime $queueForRealtime, Document $project, Document $video, array $permissions): void
    {
        $payload = $video->getArrayCopy();
        // Video rows are project-internal and carry no ACL; stamp the source
        // bucket/file read roles (plus the console team, see
        // sourceReadPermissions()) so realtime delivery matches the HTTP access
        // model — a video backed by a private file must not broadcast its
        // details to every subscriber.
        if (empty($payload['$permissions'])) {
            $payload['$permissions'] = $permissions;
        }

        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('videos.[videoId].update')
            ->setParam('videoId', $video->getId())
            ->setPayload($payload)
            ->trigger();
    }

    /**
     * @return array{basePath: string, inDir: string, outDir: string}
     */
    private function workspace(string $projectId, string $videoId): array
    {
        $root = $this->getTmpPath($projectId, $videoId);
        $basePath = $root . '/' . \uniqid('', true);
        $inDir = $basePath . '/in/';
        $outDir = $basePath . '/out/';

        if (!\mkdir($inDir, 0755, true) && !\is_dir($inDir)) {
            throw new \Exception('Failed to create temp input directory');
        }
        if (!\mkdir($outDir, 0755, true) && !\is_dir($outDir)) {
            throw new \Exception('Failed to create temp output directory');
        }

        return [
            'basePath' => $basePath,
            'inDir' => $inDir,
            'outDir' => $outDir,
        ];
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
            return;
        }

        Console::info('Removing files from [' . $basePath . ']');
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

        Console::info('Downloading file: ' . $basename . ' to ' . $inDir);

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
        Document $file,
        string $inPath,
        ?Encoder $encoder = null
    ): Document {
        $info = ($encoder ?? $this->encoder())->probe($inPath);
        $attrs = $this->attributes($info);

        Console::info(
            'Input video id: ' . $video->getId() . PHP_EOL
            . 'Input name: ' . $file->getAttribute('name') . PHP_EOL
            . 'Input width: ' . ($attrs['width'] ?? 0) . ' px' . PHP_EOL
            . 'Input height: ' . ($attrs['height'] ?? 0) . ' px' . PHP_EOL
            . 'Input duration: ' . (($attrs['duration'] ?? 0) / 1000) . ' Sec'
        );

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
     */
    private function sanitizeMeta(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \preg_replace('/[^\p{L}\p{N} .,:;\/@=+_\-]/u', '', $value);
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

            Console::info('Uploading ' . $fileName);
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
        return new Encoder(new FFmpeg(threads: 4));
    }

    /**
     * Read permissions to stamp onto rendition realtime payloads: the source
     * bucket's and file's readers, plus the project's console team so the
     * console receives progress events.
     *
     * @return array<string>
     */
    private function sourceReadPermissions(Database $dbForProject, Document $project, Document $video): array
    {
        $roles = [];

        $teamId = (string) $project->getAttribute('teamId', '');
        if ($teamId !== '') {
            $roles[] = Role::team($teamId)->toString();
        }

        try {
            $bucket = $dbForProject->getDocument('buckets', $video->getAttribute('bucketId', ''));
            if (!$bucket->isEmpty()) {
                $roles = \array_merge($roles, $bucket->getRead());

                $file = $dbForProject->getDocument(
                    'bucket_' . $bucket->getSequence(),
                    $video->getAttribute('fileId', '')
                );
                $roles = \array_merge($roles, $file->getRead());
            }
        } catch (\Throwable) {
            // Source may be mid-delete; the console team role above still applies.
        }

        return \array_map(
            static fn (string $role) => Permission::read(Role::parse($role)),
            \array_values(\array_unique($roles))
        );
    }

    /**
     * Publishes a rendition change on the project's realtime channels.
     *
     * Rendition rows carry no ACL of their own, and the Realtime adapter derives
     * delivery roles from the payload's read permissions — an empty set means the
     * event is silently dropped. Stamp the roles resolved from the source
     * bucket/file (see sourceReadPermissions()) so subscribers receive the event.
     *
     * @param array<string> $permissions
     */
    private function notify(
        Realtime $queueForRealtime,
        Document $project,
        Document $rendition,
        string $action,
        array $permissions
    ): void {
        $payload = $rendition->getArrayCopy();
        if (empty($payload['$permissions'])) {
            $payload['$permissions'] = $permissions;
        }

        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('videos.[videoId].renditions.[renditionId].' . $action)
            ->setParam('videoId', $rendition->getAttribute('videoId', ''))
            ->setParam('renditionId', $rendition->getId())
            ->setPayload($payload)
            ->trigger();
    }
}
