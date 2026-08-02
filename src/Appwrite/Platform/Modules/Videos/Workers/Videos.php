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
use DirectoryIterator;
use DOMDocument;
use FFMpeg\FFProbe;
use Mhor\MediaInfo\MediaInfo;
use Streaming\FFMpeg;
use Streaming\Format\StreamFormat;
use Streaming\Format\X264;
use Streaming\Media;
use Streaming\Representation;
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

            if (empty($video->getAttribute('duration'))) {
                $video = $this->probe($dbForProject, $video, $file, $inPath);
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

            $interval = 2;
            $ranges = [
                ['from' => 120, 'to' => 600, 'interval' => 5],
                ['from' => 600, 'to' => 1800, 'interval' => 10],
                ['from' => 1800, 'to' => 3600, 'interval' => 20],
                ['from' => 3600, 'to' => 99999, 'interval' => 30],
            ];

            $durationSec = ((int) $video->getAttribute('duration', 0)) / 1000;

            foreach ($ranges as $range) {
                if ($durationSec > $range['from'] && $durationSec <= $range['to']) {
                    $interval = $range['interval'];
                    break;
                }
            }

            $thumbWidth = 160;
            $thumbHeight = (int) \round($thumbWidth / ($width / $height));
            $tile = '5x5';
            $tileParts = \explode('x', $tile);
            $cols = (int) $tileParts[0];
            $rows = (int) $tileParts[1];

            $stdout = '';
            $stderr = '';
            $cmd = \implode(' ', [
                '/usr/bin/ffmpeg',
                '-y',
                '-i ' . \escapeshellarg($inPath),
                '-hide_banner',
                '-loglevel error',
                '-vsync vfr',
                '-vf ' . \escapeshellarg(
                    'select=isnan(prev_selected_t)+gte(t-prev_selected_t\,' . $interval . '),'
                    . 'scale=' . $thumbWidth . ':' . $thumbHeight . ',tile=' . $tile
                ),
                '-qscale:v 3',
                \escapeshellarg($workspace['outDir'] . 'sprite%d.jpg'),
            ]);

            $code = Console::execute($cmd, '', $stdout, $stderr, 0);

            if ($code !== 0) {
                throw new \Exception('ffmpeg sprite extraction failed: ' . $stderr);
            }

            $cellsPerSheet = $cols * $rows;
            $images = (int) \ceil(($durationSec / $interval) / $cellsPerSheet);
            $data = "WEBVTT";
            $timelineDir = $deviceForVideos->getPath($video->getId()) . '/timeline/';
            $counter = 0;

            for ($image = 1; $image <= $images; $image++) {
                $localFile = $workspace['outDir'] . 'sprite' . $image . '.jpg';

                if (!\is_file($localFile)) {
                    break;
                }

                $fileName = 'sprite' . $image . '.jpg';
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
                $url = 'previews/' . $preview->getId();

                for ($col = 0; $col < $cols; $col++) {
                    for ($row = 0; $row < $rows; $row++) {
                        $start = \gmdate('H:i:s', $counter * $interval);
                        $end = \gmdate('H:i:s', ($counter + 1) * $interval);
                        $data .= "\n" . $start . ' --> ' . $end . "\n"
                            . $url . '#xywh=' . ($row * $thumbWidth) . ',' . ($col * $thumbHeight)
                            . ',' . $thumbWidth . ',' . $thumbHeight;
                        $counter++;
                    }
                }
            }

            if ($counter > 0) {
                $vttPath = $deviceForVideos->getPath($video->getId() . '/timeline') . '/timeline.vtt';
                $deviceForVideos->write($vttPath, $data, 'text/vtt');
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

            if (empty($video->getAttribute('duration'))) {
                $video = $this->probe($dbForProject, $video, $file, $inPath);
            }

            $ffprobe = FFProbe::create();
            $ffmpeg = FFMpeg::create([
                'timeout' => 0,
                'ffmpeg.threads' => 4,
            ]);

            if (!$ffprobe->isValid($inPath)) {
                throw new \Exception('Not a valid media file: ' . $inPath);
            }

            $media = $ffmpeg->open($inPath);
            $outPath = $workspace['outDir'] . $video->getId();

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

            $representation = (new Representation())
                ->setKiloBitRate((int) $profile->getAttribute('videoBitRate'))
                ->setAudioKiloBitRate((int) $profile->getAttribute('audioBitRate'))
                ->setResize(
                    (int) $profile->getAttribute('width'),
                    (int) $profile->getAttribute('height')
                );

            Console::info(
                'Encoding video ' . $video->getId()
                . ' as ' . $rendition->getAttribute('name')
                . ' (' . $output . ')'
            );

            $format = new X264();
            $format->on('progress', function ($media, $format, $percentage) use ($dbForProject, $queueForRealtime, $project, &$rendition) {
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
            });

            $this->transcode($media, $format, $representation, $output, $outPath);
            unset($media);

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

            $metadata = [];
            $targetDuration = null;

            if ($output === Base::OUTPUT_HLS) {
                // The fork passes master_pl_name=master.m3u8 to ffmpeg; variant
                // playlists land as siblings named "{file}_%v_{height}p.m3u8".
                $streams = $this->parseHlsMaster($workspace['outDir'] . 'master.m3u8');

                foreach ($streams as $stream) {
                    $playlist = $this->parseHlsPlaylist($workspace['outDir'] . $stream['path']);

                    foreach ($playlist['segments'] as $segment) {
                        $dbForProject->createDocument('videos_renditions_segments', new Document([
                            'renditionId' => $rendition->getId(),
                            'renditionInternalId' => $rendition->getSequence(),
                            'streamId' => (int) $stream['id'],
                            'fileName' => $segment['fileName'],
                            'path' => $path,
                            'duration' => $segment['duration'],
                        ]));
                    }

                    if ($targetDuration === null && !empty($playlist['targetDuration'])) {
                        $targetDuration = $playlist['targetDuration'];
                    }
                }

                $metadata = ['hls' => $streams];
            } else {
                $mpdPath = $outPath . '.mpd';
                $parsed = $this->parseMpd($mpdPath);

                foreach ($parsed['segments'] as $segment) {
                    $dbForProject->createDocument('videos_renditions_segments', new Document([
                        'renditionId' => $rendition->getId(),
                        'renditionInternalId' => $rendition->getSequence(),
                        'streamId' => $segment['streamId'],
                        'fileName' => $segment['fileName'],
                        'path' => $path,
                        'isInit' => $segment['isInit'],
                    ]));
                }

                $metadata = ['mpd' => $parsed['metadata']];
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
            $this->notify($queueForRealtime, $project, $rendition, 'update');

            Console::info('Rendition ' . $rendition->getId() . ' conversion done');

            $storageBytes = $this->uploadDir(
                $workspace['outDir'],
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
     * Probe the source with mediainfo and sparsely update the videos document.
     */
    private function probe(
        Database $dbForProject,
        Document $video,
        Document $file,
        string $inPath
    ): Document {
        $mediaInfo = new MediaInfo();
        $container = $mediaInfo->getInfo($inPath);
        $general = $container->getGeneral();

        $attrs = [
            'duration' => $general->has('duration') ? $general->get('duration')->getMilliseconds() : 0,
            'format' => $general->has('format') ? $general->get('format')->getShortName() : '',
        ];

        foreach ($container->getVideos() as $track) {
            $videoFormat = $track->has('format') ? $track->get('format')->getShortName() : '';
            $attrs['height'] = $track->has('height') ? $track->get('height')->getAbsoluteValue() : 0;
            $attrs['width'] = $track->has('width') ? $track->get('width')->getAbsoluteValue() : 0;
            $attrs['aspectRatio'] = $track->has('display_aspect_ratio')
                ? $track->get('display_aspect_ratio')->getTextValue()
                : '';
            $attrs['videoFormat'] = $videoFormat;
            $attrs['videoFormatProfile'] = $track->has('format_profile') ? $track->get('format_profile') : '';
            $attrs['videoFrameRate'] = $track->has('frame_rate')
                ? (string) $track->get('frame_rate')->getAbsoluteValue()
                : '';
            $attrs['videoFrameRateMode'] = $track->has('frame_rate_mode')
                ? $track->get('frame_rate_mode')->getFullName()
                : '';
            $attrs['videoBitRate'] = $track->has('bit_rate') ? $track->get('bit_rate')->getAbsoluteValue() : 0;
            $attrs['videoCodec'] = $track->has('codec_id') ? (string) $track->get('codec_id') : $videoFormat;
        }

        foreach ($container->getAudios() as $track) {
            $audioFormat = $track->has('format') ? (string) $track->get('format')->getShortName() : '';
            $attrs['audioFormat'] = $audioFormat;
            $attrs['audioSampleRate'] = $track->has('sampling_rate')
                ? (string) $track->get('sampling_rate')->getAbsoluteValue()
                : '';
            $attrs['audioBitRate'] = $track->has('bit_rate') ? $track->get('bit_rate')->getAbsoluteValue() : 0;
            $attrs['audioCodec'] = $track->has('codec_id') ? (string) $track->get('codec_id') : $audioFormat;
        }

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

    private function transcode(
        Media $media,
        StreamFormat $format,
        Representation $representation,
        string $output,
        string $outPath
    ): void {
        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1',
            '-crf', '22',
            '-bf', '3',
            '-force_key_frames', 'expr:gte(t,n_forced*2)',
        ];

        $segmentSize = 6;

        if ($output === Base::OUTPUT_DASH) {
            // Playback serves each segment by document id, so keep the muxer on
            // explicit <SegmentList>/<SegmentURL> tags (fork defaults are already
            // 0; set them explicitly so a future default change cannot regress).
            $media->dash()
                ->setFormat($format)
                ->setSegDuration($segmentSize)
                ->setUseTemplate(0)
                ->setUseTimeLine(0)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($outPath);
            return;
        }

        $media->hls()
            ->setFormat($format)
            ->setHlsTime($segmentSize)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->save($outPath);
    }

    /**
     * Upload every file in $localDir to $remoteDir on the videos device.
     *
     * @param callable(int):void|null $onFile
     */
    private function uploadDir(
        string $localDir,
        string $remoteDir,
        Device $deviceForVideos,
        ?callable $onFile = null
    ): int {
        $bytes = 0;
        $dir = new DirectoryIterator($localDir);

        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isFile()) {
                continue;
            }

            $localPath = $localDir . $fileinfo->getFilename();
            $data = (new Local('/'))->read($localPath);
            $bytes += \strlen($data);

            Console::info('Uploading ' . $fileinfo->getFilename());
            $deviceForVideos->write(
                $remoteDir . $fileinfo->getFilename(),
                $data,
                \mime_content_type($localPath) ?: 'application/octet-stream'
            );

            if ($onFile !== null) {
                $onFile($fileinfo->key());
            }
        }

        return $bytes;
    }

    /**
     * Parse an HLS master playlist into the metadata.hls[] shape the playback
     * endpoint expects. Stream ids are assigned sequentially in order of
     * appearance — the stream manifest route only accepts small integer ids.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseHlsMaster(string $path): array
    {
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new \Exception('Unable to open HLS master playlist: ' . $path);
        }

        $files = [];
        $pending = null;

        foreach ($lines as $line) {
            $line = \trim($line);

            if (\str_starts_with($line, '#EXT-X-MEDIA:')) {
                $attr = $this->parseHlsAttributes(\substr($line, \strlen('#EXT-X-MEDIA:')));

                if (($attr['TYPE'] ?? '') !== 'AUDIO' || empty($attr['URI'])) {
                    continue;
                }

                $entry = [
                    'id' => \count($files),
                    'path' => \basename($attr['URI']),
                    'type' => 'audio',
                ];
                if (!empty($attr['LANGUAGE'])) {
                    $entry['language'] = $attr['LANGUAGE'];
                }
                if (!empty($attr['NAME'])) {
                    $entry['name'] = $attr['NAME'];
                }

                $files[] = $entry;
                continue;
            }

            if (\str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $pending = $this->parseHlsAttributes(\substr($line, \strlen('#EXT-X-STREAM-INF:')));
                continue;
            }

            if ($pending === null || $line === '' || \str_starts_with($line, '#')) {
                continue;
            }

            // The URI line following #EXT-X-STREAM-INF; variant playlists are
            // written as siblings of the master, so keep just the file name.
            $entry = [
                'id' => \count($files),
                'path' => \basename($line),
                'type' => 'video',
            ];
            if (!empty($pending['RESOLUTION'])) {
                $entry['resolution'] = $pending['RESOLUTION'];
            }
            if (!empty($pending['BANDWIDTH'])) {
                $entry['bandwidth'] = $pending['BANDWIDTH'];
            }
            if (!empty($pending['CODECS'])) {
                $entry['codecs'] = $pending['CODECS'];
            }

            $files[] = $entry;
            $pending = null;
        }

        return $files;
    }

    /**
     * Split an M3U8 attribute list, keeping commas inside quoted values
     * (e.g. CODECS="avc1.64001f,mp4a.40.2") intact.
     *
     * @return array<string, string>
     */
    private function parseHlsAttributes(string $list): array
    {
        $attributes = [];

        if (\preg_match_all('/([A-Z0-9\-]+)=("[^"]*"|[^,]*)/', $list, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = \trim($match[2], '"');
            }
        }

        return $attributes;
    }

    /**
     * @return array{targetDuration: string|null, segments: array<int, array{fileName: string, duration: string}>}
     */
    private function parseHlsPlaylist(string $path): array
    {
        $segments = [];
        $targetDuration = null;
        $duration = null;
        $handle = \fopen($path, 'r');

        if ($handle === false) {
            throw new \Exception('Unable to open HLS playlist: ' . $path);
        }

        try {
            while (($line = \fgets($handle)) !== false) {
                $line = \str_replace([',', "\r", "\n"], '', $line);

                if (\str_contains($line, '#EXT-X-TARGETDURATION')) {
                    $targetDuration = \str_replace('#EXT-X-TARGETDURATION:', '', $line);
                }

                if (\str_contains($line, '#EXTINF')) {
                    $duration = \str_replace('#EXTINF:', '', $line);
                }

                if (\str_contains($line, '.ts') || \str_contains($line, '.vtt') || \str_contains($line, '.m4s')) {
                    if ($duration !== null) {
                        $segments[] = [
                            'fileName' => $line,
                            'duration' => $duration,
                        ];
                        $duration = null;
                    }
                }
            }
        } finally {
            \fclose($handle);
        }

        return [
            'targetDuration' => $targetDuration,
            'segments' => $segments,
        ];
    }

    /**
     * Parse an encoder-produced MPD into the structured metadata.mpd shape the
     * DASH playback endpoint expects, plus a flat segment list for DB rows.
     *
     * @return array{
     *   metadata: array{attributes: array<string, string>, adaptations: array<int, array<string, mixed>>},
     *   segments: array<int, array{isInit: int, streamId: int, fileName: string}>
     * }
     */
    private function parseMpd(string $path): array
    {
        if (!\is_file($path)) {
            throw new \Exception('DASH MPD not found: ' . $path);
        }

        $xml = new DOMDocument();
        $previous = \libxml_use_internal_errors(true);

        if (!$xml->load($path)) {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
            throw new \Exception('Unable to parse DASH MPD: ' . $path);
        }

        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        $mpd = $xml->documentElement;
        if ($mpd === null) {
            throw new \Exception('Empty DASH MPD: ' . $path);
        }

        $attrKeys = [
            'profiles',
            'type',
            'mediaPresentationDuration',
            'maxSegmentDuration',
            'minBufferTime',
        ];
        $attributes = [];
        foreach ($attrKeys as $key) {
            if ($mpd->hasAttribute($key)) {
                $attributes[$key] = $mpd->getAttribute($key);
            }
        }

        $adaptations = [];
        $segments = [];
        $streamId = 0;

        foreach ($mpd->getElementsByTagName('AdaptationSet') as $adaptationNode) {
            $adaptationAttrs = [];
            foreach ([
                'contentType',
                'startWithSAP',
                'segmentAlignment',
                'bitstreamSwitching',
                'frameRate',
                'maxWidth',
                'par',
                'lang',
            ] as $key) {
                if ($adaptationNode->hasAttribute($key)) {
                    $adaptationAttrs[$key] = $adaptationNode->getAttribute($key);
                }
            }

            $representationNode = $adaptationNode->getElementsByTagName('Representation')->item(0);
            $representationAttrs = [];
            $segmentListAttrs = [];

            if ($representationNode !== null) {
                foreach ([
                    'id',
                    'mimeType',
                    'codecs',
                    'bandwidth',
                    'width',
                    'height',
                    'sar',
                    'audioSamplingRate',
                ] as $key) {
                    if ($representationNode->hasAttribute($key)) {
                        $representationAttrs[$key] = $representationNode->getAttribute($key);
                    }
                }

                $segmentList = $representationNode->getElementsByTagName('SegmentList')->item(0);

                if ($segmentList !== null) {
                    foreach (['timescale', 'duration', 'startNumber'] as $key) {
                        if ($segmentList->hasAttribute($key)) {
                            $segmentListAttrs[$key] = $segmentList->getAttribute($key);
                        }
                    }

                    foreach ($segmentList->getElementsByTagName('Initialization') as $init) {
                        if ($init->hasAttribute('sourceURL')) {
                            $segments[] = [
                                'isInit' => 1,
                                'streamId' => $streamId,
                                'fileName' => $init->getAttribute('sourceURL'),
                            ];
                        }
                    }

                    foreach ($segmentList->getElementsByTagName('SegmentURL') as $segmentUrl) {
                        if ($segmentUrl->hasAttribute('media')) {
                            $segments[] = [
                                'isInit' => 0,
                                'streamId' => $streamId,
                                'fileName' => $segmentUrl->getAttribute('media'),
                            ];
                        }
                    }
                }
            }

            $adaptations[] = [
                'id' => $streamId,
                'attributes' => $adaptationAttrs,
                'representation' => [
                    'attributes' => $representationAttrs,
                    'segmentList' => [
                        'attributes' => $segmentListAttrs,
                    ],
                ],
            ];

            $streamId++;
        }

        return [
            'metadata' => [
                'attributes' => $attributes,
                'adaptations' => $adaptations,
            ],
            'segments' => $segments,
        ];
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
