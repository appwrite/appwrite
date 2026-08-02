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
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\Video\Adapter\FFmpeg;
use Utopia\Video\Encoder;
use Utopia\Video\Format\X264;
use Utopia\Video\Info;
use Utopia\Video\Output\Dash;
use Utopia\Video\Output\Hls;
use Utopia\Video\Package;
use Utopia\Video\Packager;
use Utopia\Video\Progress;
use Utopia\Video\Representation;
use Utopia\Video\Tile;
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
            VideoAction::Timeline => $this->timeline(
                $dbForProject,
                $deviceForFiles,
                $deviceForVideos,
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
                $deviceForFiles,
                $deviceForVideos,
                $queueForRealtime,
                $usage,
                $publisherForUsage,
                $project,
                $videoMessage
            ),
        };
    }

    /**
     * Probe the source, tile sprite sheets and emit a relative WebVTT timeline.
     */
    private function timeline(
        Database $dbForProject,
        Device $deviceForFiles,
        Device $deviceForVideos,
        VideoMessage $videoMessage
    ): void {
        $workspace = $this->workspace();

        try {
            $video = $videoMessage->video;
            $file = $this->resolveFile($dbForProject, $video->getAttribute('bucketId', ''), $video->getAttribute('fileId', ''));
            $inPath = $this->download($deviceForFiles, $file, $workspace['inDir']);

            $encoder = $this->encoder();

            if (empty($video->getAttribute('duration'))) {
                $video = $this->probe($dbForProject, $video, $file, $inPath, $encoder);
            }

            if (empty($video->getAttribute('videoBitRate'))) {
                Console::warning('Videos worker: source has no video track; skipping timeline for ' . $video->getId());
                return;
            }

            $width = (int) $video->getAttribute('width', 0);
            $height = (int) $video->getAttribute('height', 0);

            if ($width <= 0 || $height <= 0) {
                Console::warning('Videos worker: source has no dimensions; skipping timeline for ' . $video->getId());
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
                $deviceForVideos->write($vttPath, $vtt, 'text/vtt');
                Console::info('Uploaded timeline vtt for video ' . $video->getId());
            }
        } finally {
            $this->cleanup($workspace['basePath']);
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

        $workspace = $this->workspace();

        try {
            $video = $videoMessage->video;

            if (empty($video->getAttribute('duration'))) {
                $sourceFile = $this->resolveFile(
                    $dbForProject,
                    $video->getAttribute('bucketId', ''),
                    $video->getAttribute('fileId', '')
                );
                $inPath = $this->download($deviceForFiles, $sourceFile, $workspace['inDir']);
                $video = $this->probe($dbForProject, $video, $sourceFile, $inPath);
            }

            $segments = $dbForProject->find('videos_subtitles_segments', [
                Query::equal('subtitleInternalId', [$subtitle->getSequence()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);

            foreach ($segments as $segment) {
                $dbForProject->deleteDocument('videos_subtitles_segments', $segment->getId());
            }

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
                $srt = new SubripFile($downloaded);
                $srt->convertTo('webvtt')->save($subtitlePath);
            } elseif (\in_array($ext, ['vtt', 'webvtt'], true)) {
                if (!\copy($downloaded, $subtitlePath)) {
                    throw new \Exception('Failed to stage WebVTT subtitle');
                }
            } else {
                // text/plain and application/x-subrip without a .srt extension: try
                // Subrip parsing, then fall back to a straight copy.
                try {
                    $srt = new SubripFile($downloaded);
                    $srt->convertTo('webvtt')->save($subtitlePath);
                } catch (\Throwable) {
                    if (!\copy($downloaded, $subtitlePath)) {
                        throw new \Exception('Failed to stage subtitle as WebVTT');
                    }
                }
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
                (new Local('/'))->read($subtitlePath),
                'text/vtt'
            );

            $dbForProject->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                new Document([
                    'targetDuration' => $duration,
                    'status' => Base::STATUS_READY,
                    'path' => $fullPath,
                ])
            );
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
        Device $deviceForFiles,
        Device $deviceForVideos,
        Realtime $queueForRealtime,
        Context $usage,
        UsagePublisher $publisherForUsage,
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

        $workspace = $this->workspace();
        $startedAt = \microtime(true);
        $storageBytes = 0;
        $output = $videoMessage->output !== ''
            ? $videoMessage->output
            : (string) $rendition->getAttribute('output', Base::OUTPUT_HLS);

        try {
            $video = $videoMessage->video;
            $file = $this->resolveFile(
                $dbForProject,
                $video->getAttribute('bucketId', ''),
                $video->getAttribute('fileId', '')
            );
            $inPath = $this->download($deviceForFiles, $file, $workspace['inDir']);

            $ffmpeg = new FFmpeg(threads: 4);
            $packager = new Packager($ffmpeg);

            if (empty($video->getAttribute('duration'))) {
                $video = $this->probe($dbForProject, $video, $file, $inPath, new Encoder($ffmpeg));
            }

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

            $target = $output === Base::OUTPUT_DASH
                ? (new Dash())->template(false)->timeline(false)->segment(6)->manifests(false)
                : (new Hls())->segment(6)->manifests(false);

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
        }
    }

    /**
     * @return array{basePath: string, inDir: string, outDir: string}
     */
    private function workspace(): array
    {
        $basePath = '/tmp/videos/' . \uniqid('', true);
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
        if ($basePath === '' || !\str_starts_with($basePath, '/tmp/videos/')) {
            return;
        }

        $stdout = '';
        $stderr = '';
        $code = Console::execute('rm -rf ' . \escapeshellarg($basePath), '', $stdout, $stderr, 3);

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
            $data = $deviceForFiles->read($fullPath);

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

            if (!$local->write($localPath, $data, $file->getAttribute('mimeType'))) {
                throw new \Exception('Unable to write decrypted source to ' . $localPath);
            }
        } elseif (!$deviceForFiles->transfer($fullPath, $localPath, $local)) {
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
                $dbForProject->createDocument('videos_renditions_segments', new Document(\array_filter([
                    'renditionId' => $rendition->getId(),
                    'renditionInternalId' => $rendition->getSequence(),
                    'streamId' => $streamId,
                    'fileName' => $segment->file,
                    'path' => $path,
                    'duration' => $output === Base::OUTPUT_HLS && !$segment->init
                        ? (string) $segment->duration
                        : null,
                    'isInit' => $segment->init ? 1 : 0,
                ], fn ($value) => $value !== null)));
            }

            if ($targetDuration === null && $variant->target > 0) {
                $targetDuration = (string) (int) \ceil($variant->target);
            }
        }

        if ($output === Base::OUTPUT_HLS) {
            $metaTarget = $package->metadata()['targetDuration'] ?? null;
            if ($targetDuration === null && $metaTarget !== null && (float) $metaTarget > 0) {
                $targetDuration = (string) (int) \ceil((float) $metaTarget);
            }

            return [['hls' => $streams], $targetDuration];
        }

        return [['mpd' => $this->mpdMeta($package)], $targetDuration];
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
            $bytes += \strlen($data);
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
