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
                $queueForRealtime,
                $publisherForVideos,
                $project,
                $videoMessage
            ),
            VideoAction::Timeline => $this->timeline(
                $dbForProject,
                $deviceForVideos,
                $publisherForVideos,
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
     * Fetch the source onto videos-tmp once, probe it, then fan out timeline
     * and every waiting encode.
     */
    private function downloadSource(
        Database $dbForProject,
        Device $deviceForFiles,
        Realtime $queueForRealtime,
        VideoPublisher $publisherForVideos,
        Document $project,
        VideoMessage $videoMessage
    ): void {
        $projectId = $videoMessage->project->getId();
        $videoId = $videoMessage->video->getId();
        $root = $this->scratchRoot($projectId, $videoId);
        $lockPath = $root . '/source.lock';

        if (!\is_dir($root) && !\mkdir($root, 0755, true) && !\is_dir($root)) {
            throw new \Exception('Failed to create videos-tmp directory');
        }

        $lock = \fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \Exception('Failed to open source lock');
        }

        try {
            if (!\flock($lock, LOCK_EX)) {
                throw new \Exception('Failed to lock source download');
            }

            $video = $dbForProject->getDocument('videos', $videoId);
            if ($video->isEmpty()) {
                throw new \Exception('Video not found: ' . $videoId);
            }

            $file = $this->resolveFile(
                $dbForProject,
                $video->getAttribute('bucketId', ''),
                $video->getAttribute('fileId', '')
            );
            $sourcePath = $this->sourcePath($projectId, $videoId);
            $fetched = !$this->sourceReady($sourcePath, $video)
                || $video->getAttribute('status') !== Base::STATUS_READY;
            // Probe writes duration; capture this before so a later refetch
            // (source GC'd after timeline) does not extract subtitles a second
            // time and invalidate ids the client already observed.
            $needsTimeline = empty($video->getAttribute('duration'));

            if ($fetched) {
                $this->fetchSource(
                    $dbForProject,
                    $deviceForFiles,
                    $queueForRealtime,
                    $project,
                    $video,
                    $file,
                    $sourcePath
                );
                $video = $this->probe($dbForProject, $video, $file, $sourcePath);
            }

            $video = $this->setVideoStatus(
                $dbForProject,
                $queueForRealtime,
                $project,
                $video,
                Base::STATUS_READY,
                (int) $video->getAttribute('chunksTotal', 1),
                (int) $video->getAttribute('chunksTotal', 1)
            );

            $this->fanOut($dbForProject, $publisherForVideos, $project, $video, $needsTimeline);
        } catch (\Throwable $th) {
            $video = $dbForProject->getDocument('videos', $videoId);
            if (!$video->isEmpty()) {
                $this->setVideoStatus(
                    $dbForProject,
                    $queueForRealtime,
                    $project,
                    $video,
                    Base::STATUS_ERROR
                );
                $this->failWaitingRenditions($dbForProject, $queueForRealtime, $project, $video);
            }

            throw $th;
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }

    /**
     * Probe the source, tile sprite sheets and emit a relative WebVTT timeline.
     */
    private function timeline(
        Database $dbForProject,
        Device $deviceForVideos,
        VideoPublisher $publisherForVideos,
        VideoMessage $videoMessage
    ): void {
        $video = $videoMessage->video;
        $projectId = $videoMessage->project->getId();
        $workspace = $this->jobWorkspace($projectId, $video->getId());

        try {
            Console::info('Videos worker: timeline started for video ' . $video->getId());
            $inPath = $this->assertSource($dbForProject, $publisherForVideos, $videoMessage);
            if ($inPath === null) {
                return;
            }

            $encoder = $this->encoder();

            // Prefer dimensions over bitrate: many containers (VBR MKV, some DivX)
            // report width/height but leave bitrate as 0, and empty(0) is true in PHP.
            $width = (int) $video->getAttribute('width', 0);
            $height = (int) $video->getAttribute('height', 0);

            if ($width <= 0 || $height <= 0) {
                Console::warning('Videos worker: source has no video track; skipping timeline for ' . $video->getId());
                return;
            }

            $this->extractEmbeddedSubtitles(
                $dbForProject,
                $deviceForVideos,
                $video,
                $inPath,
                $workspace['outDir'],
                $encoder
            );

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
            $this->tryRelease($dbForProject, $projectId, $video->getId());
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

        $video = $videoMessage->video;
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
        $workspace = $this->jobWorkspace($projectId, $videoId);
        $startedAt = \microtime(true);
        $storageBytes = 0;
        $output = $videoMessage->output !== ''
            ? $videoMessage->output
            : (string) $rendition->getAttribute('output', Base::OUTPUT_HLS);

        try {
            $current = $dbForProject->getDocument('videos_renditions', $rendition->getId());
            if ($current->isEmpty() || $current->getAttribute('status') !== Base::STATUS_WAITING) {
                return;
            }

            $inPath = $this->assertSource($dbForProject, $publisherForVideos, $videoMessage);
            if ($inPath === null) {
                return;
            }

            $video = $dbForProject->getDocument('videos', $videoId);
            $ffmpeg = new FFmpeg(threads: 4);
            $packager = new Packager($ffmpeg);

            if (!$packager->valid($inPath)) {
                throw new \Exception('Not a valid media file: ' . $inPath);
            }

            $rendition = $dbForProject->updateDocument(
                'videos_renditions',
                $rendition->getId(),
                new Document([
                    'startedAt' => DateTime::now(),
                    'status' => Base::STATUS_STARTED,
                    'progress' => '0',
                ])
            );
            $this->notify($queueForRealtime, $project, $rendition, 'update');

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

            $package = $packager
                ->open($inPath)
                ->format($format)
                ->add($representation)
                ->output($target)
                ->on(Packager::PROGRESS, function (mixed $progress) use ($dbForProject, $queueForRealtime, $project, &$rendition) {
                    if (!$progress instanceof Progress) {
                        return;
                    }

                    $percentage = (int) \round($progress->percent);

                    if ($percentage % 3 !== 0) {
                        return;
                    }

                    $rendition = $dbForProject->updateDocument(
                        'videos_renditions',
                        $rendition->getId(),
                        new Document([
                            'progress' => (string) $percentage,
                        ])
                    );
                    $this->notify($queueForRealtime, $project, $rendition, 'update');
                })
                ->on(Packager::LOG, function (mixed $line) {
                    if (\is_string($line) && \trim($line) !== '') {
                        Console::info('Videos worker: packager: ' . \trim($line));
                    }
                })
                ->pack(\rtrim($workspace['outDir'], '/'));

            $path = $deviceForVideos->getPath($video->getId())
                . '/' . $rendition->getAttribute('name')
                . '-' . $rendition->getId() . '/';

            // Drop any leftover segments from a previous attempt at the same id.
            $oldSegments = $dbForProject->find('videos_renditions_segments', [
                Query::equal('renditionInternalId', [$rendition->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
            foreach ($oldSegments as $segment) {
                $dbForProject->deleteDocument('videos_renditions_segments', $segment->getId());
            }

            [$metadata, $targetDuration] = $this->persistPackage(
                $dbForProject,
                $package,
                $rendition,
                $path,
                $output
            );

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
            $this->notify($queueForRealtime, $project, $rendition, 'update');

            Console::info('Rendition ' . $rendition->getId() . ' conversion done');

            $storageBytes = $this->uploadFiles(
                $package->files(),
                $path,
                $deviceForVideos,
                function (int $index) use ($dbForProject, $queueForRealtime, $project, &$rendition, $path) {
                    if ($index !== 0) {
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
                    $this->notify($queueForRealtime, $project, $rendition, 'update');
                }
            );

            $rendition = $dbForProject->updateDocument(
                'videos_renditions',
                $rendition->getId(),
                new Document([
                    'status' => Base::STATUS_READY,
                    'path' => $path,
                    'progress' => '100',
                ])
            );
            $this->notify($queueForRealtime, $project, $rendition, 'update');
        } catch (\Throwable $th) {
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
            $this->notify($queueForRealtime, $project, $rendition, 'update');

            Console::error(
                'Error encoding video ' . $videoMessage->video->getId() . PHP_EOL
                . 'Message: ' . $th->getMessage() . PHP_EOL
                . 'File: ' . $th->getFile() . PHP_EOL
                . 'Line: ' . $th->getLine()
            );

            throw $th;
        } finally {
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

            $this->cleanup($workspace['basePath']);
            $this->tryRelease($dbForProject, $projectId, $videoId);
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
        $segments = $dbForProject->find('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        foreach ($segments as $segment) {
            $dbForProject->deleteDocument('videos_subtitles_segments', $segment->getId());
        }

        $dir = $deviceForVideos->getPath($video->getId()) . '/subtitles/';
        $fileName = $subtitle->getId() . '.vtt';
        $fullPath = $dir . $fileName;
        $duration = (string) \number_format(((int) $video->getAttribute('duration', 0)) / 1000, 1);

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

        $wiped = $this->wipeEmbeddedSubtitles($dbForProject, $deviceForVideos, $video);
        if ($wiped > 0) {
            Console::info(
                'Videos worker: wiped ' . $wiped
                . ' prior embedded subtitle(s) on video ' . $video->getId()
            );
        }

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
        Console::info(
            'Videos worker: found ' . \count($tracks)
            . ' subtitle stream(s) in source for video ' . $video->getId()
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

            $name = $track->title
                ?? ($track->language !== null && $track->language !== '' ? $track->language : null)
                ?? ('Track ' . $track->index);

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
     * Delete auto-extracted (empty fileId) subtitle rows and their artifacts.
     *
     * @return int number of embedded rows removed
     */
    private function wipeEmbeddedSubtitles(
        Database $dbForProject,
        Device $deviceForVideos,
        Document $video
    ): int {
        $existing = $dbForProject->find('videos_subtitles', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $wiped = 0;

        foreach ($existing as $subtitle) {
            if (!empty($subtitle->getAttribute('fileId', ''))) {
                continue;
            }

            Console::info(
                'Videos worker: removing embedded subtitle ' . $subtitle->getId()
                . ' code=' . $subtitle->getAttribute('code', '')
                . ' on video ' . $video->getId()
            );
            $this->deleteSubtitleArtifacts($dbForProject, $deviceForVideos, $subtitle);
            $wiped++;
        }

        return $wiped;
    }

    /**
     * Remove segment rows, the subtitle document, and the device VTT path.
     */
    private function deleteSubtitleArtifacts(
        Database $dbForProject,
        Device $deviceForVideos,
        Document $subtitle
    ): void {
        $segments = $dbForProject->find('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        foreach ($segments as $segment) {
            $dbForProject->deleteDocument('videos_subtitles_segments', $segment->getId());
        }

        $path = $subtitle->getAttribute('path', '');
        $dbForProject->deleteDocument('videos_subtitles', $subtitle->getId());

        if (!empty($path) && $deviceForVideos->exists($path)) {
            try {
                $deviceForVideos->delete($path);
            } catch (\Throwable) {
                // Best-effort; the DB row is the source of truth.
            }
        }
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

    private function scratchRoot(string $projectId, string $videoId): string
    {
        return \rtrim(APP_STORAGE_VIDEOS_TMP, '/') . '/app-' . $projectId . '/' . $videoId;
    }

    private function sourcePath(string $projectId, string $videoId): string
    {
        return $this->scratchRoot($projectId, $videoId) . '/source';
    }

    /**
     * Per-job output directory under `{videoId}/jobs/{uniqid}/out/`.
     *
     * @return array{basePath: string, outDir: string}
     */
    private function jobWorkspace(string $projectId, string $videoId): array
    {
        $basePath = $this->scratchRoot($projectId, $videoId) . '/jobs/' . \uniqid('', true);
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

    /**
     * @return string|null local source path, or null when a download was re-queued
     */
    private function assertSource(
        Database $dbForProject,
        VideoPublisher $publisherForVideos,
        VideoMessage $videoMessage
    ): ?string {
        $video = $dbForProject->getDocument('videos', $videoMessage->video->getId());
        $path = $this->sourcePath($videoMessage->project->getId(), $video->getId());

        if ($this->sourceReady($path, $video)) {
            return $path;
        }

        $publisherForVideos->enqueue(new VideoMessage(
            project: $videoMessage->project,
            action: VideoAction::Download,
            video: $video,
            profile: $videoMessage->profile,
            rendition: $videoMessage->rendition,
            output: $videoMessage->output,
        ));

        Console::warning('Videos worker: source missing or incomplete for ' . $video->getId() . '; re-queued download');

        return null;
    }

    private function fetchSource(
        Database $dbForProject,
        Device $deviceForFiles,
        Realtime $queueForRealtime,
        Document $project,
        Document $video,
        Document $file,
        string $sourcePath
    ): void {
        $fullPath = $file->getAttribute('path', '');

        if (!$deviceForFiles->exists($fullPath)) {
            throw new \Exception('Source file missing from storage: ' . $fullPath);
        }

        $storedSize = $deviceForFiles->getFileSize($fullPath);
        $chunks = Base::chunkCount($storedSize);
        $partPath = $sourcePath . '.part';

        $video = $this->setVideoStatus(
            $dbForProject,
            $queueForRealtime,
            $project,
            $video,
            Base::STATUS_STARTED,
            $chunks,
            0
        );

        Console::info('Downloading source for video ' . $video->getId() . ' in ' . $chunks . ' chunk(s)');

        if (\is_file($partPath)) {
            \unlink($partPath);
        }
        if (\is_file($sourcePath)) {
            \unlink($sourcePath);
        }

        $handle = \fopen($partPath, 'wb');
        if ($handle === false) {
            throw new \Exception('Unable to open source part file');
        }

        try {
            $chunkSize = APP_LIMIT_UPLOAD_CHUNK_SIZE;
            for ($chunk = 1; $chunk <= $chunks; $chunk++) {
                $offset = ($chunk - 1) * $chunkSize;
                $length = (int) \min($chunkSize, $storedSize - $offset);
                $data = (string) $deviceForFiles->read($fullPath, $offset, $length);
                if (\fwrite($handle, $data) === false) {
                    throw new \Exception('Unable to write source chunk ' . $chunk);
                }

                $this->setVideoStatus(
                    $dbForProject,
                    $queueForRealtime,
                    $project,
                    $video,
                    Base::STATUS_STARTED,
                    $chunks,
                    $chunk
                );
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

            if (\file_put_contents($sourcePath, $data) === false) {
                throw new \Exception('Unable to write decrypted source');
            }
            \unlink($partPath);
        } elseif (!\rename($partPath, $sourcePath)) {
            throw new \Exception('Unable to finalise source download');
        }

        $expected = (int) $video->getAttribute('size', 0);
        $actual = \is_file($sourcePath) ? (int) \filesize($sourcePath) : 0;
        if (!Base::sourceMatches($sourcePath, $expected)) {
            throw new \Exception(
                'Source size mismatch for video ' . $video->getId()
                . ': expected ' . $expected . ', got ' . $actual
            );
        }
    }

    private function fanOut(
        Database $dbForProject,
        VideoPublisher $publisherForVideos,
        Document $project,
        Document $video,
        bool $needsTimeline
    ): void {
        $renditions = $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [Base::STATUS_WAITING]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        foreach ($renditions as $rendition) {
            $profile = $dbForProject->getDocument(
                'videos_profiles',
                $rendition->getAttribute('profileId', '')
            );
            if ($profile->isEmpty()) {
                continue;
            }

            $publisherForVideos->enqueue(new VideoMessage(
                project: $project,
                action: VideoAction::Encode,
                video: $video,
                profile: $profile,
                rendition: $rendition,
                output: (string) $rendition->getAttribute('output', ''),
            ));
        }

        if ($needsTimeline) {
            $publisherForVideos->enqueue(new VideoMessage(
                project: $project,
                action: VideoAction::Timeline,
                video: $video,
            ));
        }
    }

    private function tryRelease(Database $dbForProject, string $projectId, string $videoId): void
    {
        $video = $dbForProject->getDocument('videos', $videoId);
        if ($video->isEmpty()) {
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

        $jobs = $this->scratchRoot($projectId, $videoId) . '/jobs';
        $jobsRemain = \is_dir($jobs) && !empty(\glob($jobs . '/*', GLOB_ONLYDIR));

        if (!Base::canReleaseSource(
            (string) $video->getAttribute('status', ''),
            !empty($inFlight),
            $jobsRemain
        )) {
            return;
        }

        foreach ([
            $this->sourcePath($projectId, $videoId),
            $this->sourcePath($projectId, $videoId) . '.part',
            $this->scratchRoot($projectId, $videoId) . '/source.lock',
        ] as $path) {
            if (\is_file($path)) {
                \unlink($path);
                Console::info('Released source [' . $path . ']');
            }
        }
    }

    private function failWaitingRenditions(
        Database $dbForProject,
        Realtime $queueForRealtime,
        Document $project,
        Document $video
    ): void {
        $renditions = $dbForProject->find('videos_renditions', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('status', [Base::STATUS_WAITING]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        foreach ($renditions as $rendition) {
            $updated = $dbForProject->updateDocument(
                'videos_renditions',
                $rendition->getId(),
                new Document([
                    'status' => Base::STATUS_ERROR,
                    'endedAt' => DateTime::now(),
                ])
            );
            $this->notify($queueForRealtime, $project, $updated, 'update');
        }
    }

    private function setVideoStatus(
        Database $dbForProject,
        Realtime $queueForRealtime,
        Document $project,
        Document $video,
        string $status,
        ?int $chunksTotal = null,
        ?int $chunksUploaded = null
    ): Document {
        $data = ['status' => $status];
        if ($chunksTotal !== null) {
            $data['chunksTotal'] = $chunksTotal;
        }
        if ($chunksUploaded !== null) {
            $data['chunksUploaded'] = $chunksUploaded;
        }

        $video = $dbForProject->updateDocument('videos', $video->getId(), new Document($data));
        $this->notifyVideo($queueForRealtime, $project, $video);

        return $video;
    }

    private function notifyVideo(Realtime $queueForRealtime, Document $project, Document $video): void
    {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('videos.[videoId].update')
            ->setParam('videoId', $video->getId())
            ->setPayload($video->getArrayCopy())
            ->trigger();
    }

    /**
     * @return array{basePath: string, inDir: string, outDir: string}
     */
    private function workspace(string $projectId, string $videoId): array
    {
        $root = $this->scratchRoot($projectId, $videoId);
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
            $entry['language'] = $variant->language;
            $entry['name'] = $variant->language;
        }
        if ($variant->resolution() !== null) {
            $entry['resolution'] = $variant->resolution();
        }
        if ($variant->bandwidth > 0) {
            $entry['bandwidth'] = (string) $variant->bandwidth;
        }
        if ($variant->codecs !== null) {
            $entry['codecs'] = $variant->codecs;
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
                $attributes[$key] = (string) $raw[$key];
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
                'attributes' => $adaptationAttrs,
                'representation' => [
                    'attributes' => $representationAttrs,
                    'segmentList' => [
                        'attributes' => $segmentListAttrs,
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
     * Publishes a rendition change on the project's realtime channels.
     */
    private function notify(
        Realtime $queueForRealtime,
        Document $project,
        Document $rendition,
        string $action
    ): void {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console', $project->getId()])
            ->setEvent('videos.[videoId].renditions.[renditionId].' . $action)
            ->setParam('videoId', $rendition->getAttribute('videoId', ''))
            ->setParam('renditionId', $rendition->getId())
            ->setPayload($rendition->getArrayCopy())
            ->trigger();
    }
}
