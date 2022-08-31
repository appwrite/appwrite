<?php

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Streaming\Format\StreamFormat;
use Streaming\HLSSubtitle;
use Streaming\Media;
use Streaming\Representation;
use Streaming\RepresentationInterface;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Captioning\Format\SubripFile;
use Utopia\Storage\Device;

require_once __DIR__ . '/../init.php';

Console::title('Transcoding V1 Worker');
Console::success(APP_NAME . ' transcoding worker v1 has started');

class TranscodingV1 extends Worker
{
    /**
     * Rendition Status
     */
    private const STATUS_START     = 'started';
    private const STATUS_END       = 'ended';
    private const STATUS_UPLOADING = 'uploading';
    private const STATUS_READY     = 'ready';
    private const STATUS_ERROR     = 'error';

    private const STREAM_HLS = 'hls';
    private const STREAM_MPEG_DASH = 'dash';

    //private string $basePath = '/tmp/';
    private string $basePath = '/usr/src/code/tests/tmp/';

    private string $inDir;

    private string $outDir;

    private string $outPath;

    private string $renditionName;

    private array $audios = [];

    private Database $database;

    public function getName(): string
    {
        return "Transcoding";
    }

    public function init(): void
    {

        $this->basePath .=   $this->args['videoId'] . '/' . $this->args['profileId'];
        $this->inDir  =  $this->basePath . '/in/';
        $this->outDir =  $this->basePath . '/out/';
        @mkdir($this->inDir, 0755, true);
        @mkdir($this->outDir, 0755, true);
        $this->outPath = $this->outDir . $this->args['videoId'];
    }

    public function run(): void
    {
        $project = new Document($this->args['project']);
        $this->database = $this->getProjectDB($project->getId());

        $sourceVideo = Authorization::skip(fn() => $this->database->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['videoId']])]));
        if (empty($sourceVideo)) {
            throw new Exception('Video not found');
        }

        $profile = Authorization::skip(fn() => $this->database->findOne('videos_profiles', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['profileId']])]));
        if (empty($profile)) {
            throw new Exception('profile not found');
        }

        $bucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $sourceVideo['bucketId']));
        $file = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $bucket->getInternalId(), $sourceVideo['fileId']));
        $fileName = basename($file->getAttribute('path'));
        $inPath = $this->inDir . $fileName;
        $collection = 'videos_renditions';

        if (
            !empty($file->getAttribute('openSSLCipher')) ||
            !empty($file->getAttribute('algorithm', ''))
        ) {
            $data = $this->getFilesDevice($project->getId())->read($file->getAttribute('path'));
            if (!empty($file->getAttribute('openSSLCipher'))) {
                $data = OpenSSL::decrypt(
                    $data,
                    $file->getAttribute('openSSLCipher'),
                    App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                    0,
                    \hex2bin($file->getAttribute('openSSLIV')),
                    \hex2bin($file->getAttribute('openSSLTag'))
                );
            }

            if (!empty($file->getAttribute('algorithm', ''))) {
                $compressor = new GZIP();
                $data = $compressor->decompress($data);
            }

            $this->getFilesDevice($project->getId())->write($this->inDir . $fileName, $data, $file->getAttribute('mimeType'));
        } else {
            $this->getFilesDevice($project->getId())->transfer($file->getAttribute('path'), $this->inDir . $fileName, $this->getFilesDevice($project->getId()));
        }

        $ffprobe = FFMpeg\FFProbe::create();
        $ffmpeg = Streaming\FFMpeg::create([
            'timeout' => 0,
            'ffmpeg.threads'  => 12
        ]);

        if (!$ffprobe->isValid($inPath)) {
            throw new Exception('Not an valid FFMpeg file "' . $inPath . '"');
        }

        foreach ($ffprobe->streams($inPath)->audios()->getIterator() as $stream) {
            $this->audios[] = $stream->get('tags')['language'];
        }

        $audioStreamCount = $ffprobe->streams($inPath)->audios()->count();
        $videoStreamCount = $ffprobe->streams($inPath)->videos()->count();
        $streams = $ffprobe->streams($inPath);
        $sourceVideo
            ->setAttribute('duration', $videoStreamCount > 0 ? $streams->videos()->first()->get('duration') : null)
            ->setAttribute('height', $videoStreamCount > 0 ? $streams->videos()->first()->get('height') : null)
            ->setAttribute('width', $videoStreamCount > 0 ? $streams->videos()->first()->get('width') : null)
            ->setAttribute('videoCodec', $videoStreamCount > 0 ? $streams->videos()->first()->get('codec_name') : null)
            ->setAttribute('videoFramerate', $videoStreamCount > 0 ? $streams->videos()->first()->get('avg_frame_rate') : null)
            ->setAttribute('videoBitrate', $videoStreamCount > 0 ? $streams->videos()->first()->get('bit_rate') : null)
            ->setAttribute('audioCodec', $audioStreamCount > 0 ? $streams->audios()->first()->get('codec_name') : null)
            ->setAttribute('audioSamplerate', $audioStreamCount > 0 ? $streams->audios()->first()->get('sample_rate') : null)
            ->setAttribute('audioBitrate', $audioStreamCount > 0 ? $streams->audios()->first()->get('bit_rate') : null)
            ;
            Authorization::skip(fn() => $this->database->updateDocument(
                'videos',
                $sourceVideo->getId(),
                $sourceVideo
            ));

        $video = $ffmpeg->open($inPath);
        $this->setRenditionName($profile);

        $subs = [];
        $subtitles = Authorization::skip(fn () => $this->database->find('videos_subtitles', [
            new Query('status', Query::TYPE_EQUAL, ['']),
            new Query('videoId', Query::TYPE_EQUAL, [$this->args['videoId']])
        ]));

        foreach ($subtitles as $subtitle) {
            $subtitle->setAttribute('status', self::STATUS_START);
            Authorization::skip(fn() => $this->database->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                $subtitle
            ));

            $subtitleBucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $subtitle->getAttribute('bucketId')));
            $subtitleFile = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $subtitleBucket->getInternalId(), $subtitle->getAttribute('fileId')));
            $subtitleFileName = basename($subtitleFile->getAttribute('path'));

            if (
                !empty($subtitleFile->getAttribute('openSSLCipher')) ||
                !empty($subtitleFile->getAttribute('algorithm', ''))
            ) {
                $subtitleData = $this->getFilesDevice($project->getId())->read($subtitleFile->getAttribute('path'));

                if (!empty($subtitleFile->getAttribute('openSSLCipher'))) {
                    $subtitleData = OpenSSL::decrypt(
                        $subtitleData,
                        $subtitleFile->getAttribute('openSSLCipher'),
                        App::getEnv('_APP_OPENSSL_KEY_V' . $subtitleFile->getAttribute('openSSLVersion')),
                        0,
                        \hex2bin($subtitleFile->getAttribute('openSSLIV')),
                        \hex2bin($subtitleFile->getAttribute('openSSLTag'))
                    );
                }

                if (!empty($subtitleFile->getAttribute('algorithm', ''))) {
                    $compressor = new GZIP();
                    $subtitleData = $compressor->decompress($subtitleData);
                }

                $this->getFilesDevice($project->getId())->write($this->inDir . $subtitleFileName, $subtitleData, $subtitleFile->getAttribute('mimeType'));
            } else {
                $this->getFilesDevice($project->getId())->transfer($subtitleFile->getAttribute('path'), $this->inDir . $subtitleFileName, $this->getFilesDevice($project->getId()));
            }

            $ext = pathinfo($subtitleFileName, PATHINFO_EXTENSION);
            $subtitlePath = $this->inDir . $subtitle->getId() . '.vtt';

            if ($ext === 'srt') {
                $srt = new SubripFile($this->inDir . $subtitleFileName);
                $srt->convertTo('webvtt')->save($subtitlePath);
            }

            $subs[] = [
                 'name' => $subtitle->getAttribute('name'),
                 'code' => $subtitle->getAttribute('code'),
                 'path' => $subtitlePath,
            ];
        }

            $query = Authorization::skip(function () use ($collection, $profile) {
                    return $this->database->createDocument($collection, new Document([
                        'videoId'  => $this->args['videoId'],
                        'profileId' => $profile->getId(),
                        'name'      => $this->getRenditionName(),
                        'startedAt' => time(),
                        'status'    => self::STATUS_START,
                        'stream'    => $profile['stream'],
                    ]));
            });

        $renditionRootPath = $this->getVideoDevice($project->getId())->getPath($this->args['videoId']) . '/';
        $renditionPath = $renditionRootPath . $this->getRenditionName() . '-' . $query->getId() .  '/';

        try {
            $representation = (new Representation())
                ->setKiloBitrate($profile->getAttribute('videoBitrate'))
                ->setAudioKiloBitrate($profile->getAttribute('audioBitrate'))
                ->setResize($profile->getAttribute('width'), $profile->getAttribute('height'))
            ;

            $format = new Streaming\Format\X264();
            $format->on('progress', function ($video, $format, $percentage) use ($query, $collection) {
                if ($percentage % 3 === 0) {
                    $query->setAttribute('progress', (string)$percentage);
                    Authorization::skip(fn() => $this->database->updateDocument(
                        $collection,
                        $query->getId(),
                        $query
                    ));
                }
            });

            $general = $this->transcode($profile['stream'], $video, $format, $representation, $subs);
            if (!empty($general)) {
                foreach ($general as $key => $value) {
                    $query->setAttribute($key, (string)$value);
                }
            }

            if ($profile['stream'] === 'hls') {
                $refs = $this->getHlsSegmentsUrls($this->outDir . 'master.m3u8');
                foreach ($refs as $ref) {
                    $m3u8 = $this->getHlsSegments($this->outDir . $ref['path']);
                    if (!empty($m3u8['segments'])) {
                        foreach ($m3u8['segments'] as $segment) {
                            Authorization::skip(function () use ($segment, $project, $query, $renditionPath, $ref) {
                                return $this->database->createDocument('videos_renditions_segments', new Document([
                                    'renditionId' => $query->getId(),
                                    'representationId' => $ref['id'] + 0,
                                    'fileName' => $segment['fileName'],
                                    'path' => $renditionPath,
                                    'duration' => $segment['duration'],
                                ]));
                            });
                        }
                    }

                    $query->setAttribute('metadata', json_encode(['hls' => $refs]));
                    $query->setAttribute('targetDuration', $m3u8['targetDuration']);
                }
            } else {
                $mpd = $this->getDashSegments($this->outPath . '.mpd');
                if (!empty($mpd['segments'])) {
                    foreach ($mpd['segments'] as $segment) {
                        Authorization::skip(function () use ($segment, $project, $query, $renditionPath) {
                            return $this->database->createDocument('videos_renditions_segments', new Document([
                                'renditionId' => $query->getId(),
                                'representationId' => $segment['representationId'],
                                'fileName' => $segment['fileName'],
                                'path' => $renditionPath,
                                'isInit' => $segment['isInit'],
                                ]));
                        });
                    }
                }

                if (!empty($mpd['metadata'])) {
                    $query->setAttribute('metadata', json_encode(['mpd' => $mpd['metadata']]));
                }
            }

            $query->setAttribute('status', self::STATUS_END);
            $query->setAttribute('endedAt', time());
            Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));

            foreach ($subtitles ?? [] as $subtitle) {
                if ($profile['stream'] === 'hls') {
                    $m3u8 = $this->getHlsSegments($this->outPath . '_subtitles_' . $subtitle['code'] . '.m3u8');
                    foreach ($m3u8['segments'] ?? [] as $segment) {
                        Authorization::skip(function () use ($segment, $project, $subtitle, $renditionRootPath) {
                            return $this->database->createDocument('videos_subtitles_segments', new Document([
                                'subtitleId'  =>  $subtitle->getId(),
                                'fileName'  => $segment['fileName'],
                                'path'  => $renditionRootPath ,
                                'duration' => $segment['duration'],
                            ]));
                        });
                    }
                    $subtitle->setAttribute('targetDuration', $m3u8['targetDuration']);
                } else {
                    $this->getFilesDevice($project->getId())->transfer($this->inDir . $subtitle->getId() . '.vtt', $this->outDir . $subtitle->getId() . '.vtt', $this->getFilesDevice($project->getId()));
                }

                $subtitle->setAttribute('status', self::STATUS_READY);
                $subtitle->setAttribute('path', $renditionRootPath);
                Authorization::skip(fn() => $this->database->updateDocument(
                    'videos_subtitles',
                    $subtitle->getId(),
                    $subtitle
                ));
            }

         /** Upload & cleanup **/
            $start = 0;
            $fileNames = scandir($this->outDir);
            foreach ($fileNames as $fileName) {
                if ($fileName === '.' || $fileName === '..') {
                    //str_contains($fileName, '.json')) {
                    continue;
                }

                $data = $this->getFilesDevice($project->getId())->read($this->outDir . $fileName);
                $to = $renditionPath;
                if (str_contains($fileName, "_subtitles_") || str_contains($fileName, ".vtt")) {
                    $to = $renditionRootPath;
                }

                $this->getVideoDevice($project->getId())->write($to .  $fileName, $data, \mime_content_type($this->outDir . $fileName));
                if ($start === 0) {
                    $query->setAttribute('status', self::STATUS_UPLOADING);
                    $query->setAttribute('path', $renditionPath);
                    Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));
                    $start = 1;
                }
                //@unlink($this->outDir . $fileName);
            }

            $query->setAttribute('status', self::STATUS_READY);
            Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));
        } catch (\Throwable $th) {
            var_dump($th->getCode());
            var_dump($th->getMessage());
            $query->setAttribute('metadata', json_encode([
            'code' => $th->getCode(),
            'message' => substr($th->getMessage(), 0, 3600),
            ]));

            $query->setAttribute('status', self::STATUS_ERROR);
            Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));
        }
    }

    /**
     * @param string $stream
     * @param $video Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @param array $subtitles
     * @return string|array
     */
    private function transcode(string $stream, Media $video, StreamFormat $format, Representation $representation, array $subtitles): string | array
    {
//        $video->filters()
//            ->framerate(new FFMpeg\Coordinate\FrameRate(24), 2)
//            ;

        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1',
            '-r', '24',
            '-b_strategy', '1',
            '-bf', '3',
            '-g', '120'
        ];

        $segmentSize = 10;

        if ($stream === 'dash') {
                $dash = $video->dash()
                ->setFormat($format)
                ->setSegDuration($segmentSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath)
                ;

                return $this->getVideoStreamInfo($dash->metadata()->export(), $representation);
        }

        $hls = $video->hls();

        foreach ($subtitles as $subtitle) {
            $sub = new HLSSubtitle($subtitle['path'], $subtitle['name'], $subtitle['code']);
            $sub->default();
            $hls->subtitle($sub);
        }

        $hls->setFormat($format)
            ->setAudiolanguages($this->audios)
            ->setHlsTime($segmentSize)
            ->setHlsAllowCache(false)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->save($this->outPath)
        ;

        return $this->getVideoStreamInfo($hls->metadata()->export(), $representation);
    }
    /**
     * @param string $path
     * @return array
     */
    private function getDashSegments(string $path): array
    {
        $segments = [];
        $metadata = null;
        $handle = fopen($path, "r");
        if ($handle) {
            $representationId = -1;
            while (($line = fgets($handle)) !== false) {
                $line =  str_replace([",","\r","\n"], "", $line);
                if (str_contains($line, "<AdaptationSet")) {
                    $representationId++;
                }

                if (!str_contains($line, "SegmentURL") && !str_contains($line, "Initialization")) {
                    $metadata .= $line . PHP_EOL;
                } else {
                    $segments[] = [
                        'isInit' => str_contains($line, "Initialization") ? 1 : 0,
                        'representationId' => $representationId,
                        'fileName' => trim(str_replace(["<SegmentURL media=\"", "<Initialization sourceURL=\"", "\"/>", "\" />"], "", $line)),
                    ];
                }
            }
            fclose($handle);
        }

        return [
            'metadata' => $metadata,
            'segments' => $segments
        ];
    }

    /**
     * @param string $path
     * @return array
     */
    private function getHlsSegmentsUrls(string $path): array
    {
        $files = [];
        $handle = fopen($path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line =  str_replace([",","\r","\n"], "", $line);
                $end = strpos($line, 'm3u8');
                if ($end !== false) {
                    $start = strpos($line, $this->args['videoId']);
                    if ($start !== false) {
                        $path = substr($line, $start, ($end - $start) + 4);
                        $parts = explode('_', $path);
                        $files[] = [
                            'id' => $parts[1],
                            'type' => str_contains($line, "TYPE=AUDIO") ? 'audio' : 'video',
                            'path' => $path
                            ];
                    }
                }
            }
            fclose($handle);
        }
        return $files;
    }


    /**
     * @param string $path
     * @return array
     */
    private function getHlsSegments(string $path): array
    {
        $segments = [];
        $targetDuration = 0;
        $handle = fopen($path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line =  str_replace([",","\r","\n"], "", $line);
                if (str_contains($line, "#EXT-X-TARGETDURATION")) {
                    $targetDuration = str_replace(["#EXT-X-TARGETDURATION:"], "", $line);
                }
                if (str_contains($line, "#EXTINF")) {
                    $duration = str_replace(["#EXTINF:"], "", $line);
                }
                if (str_contains($line, ".ts") || str_contains($line, ".vtt")) {
                    if (!empty($duration)) {
                        $segments[] = [
                            'fileName' => $line,
                            'duration' => $duration
                        ];
                        $duration = null;
                    }
                }
            }
            fclose($handle);
        }
        return [
            'targetDuration' => $targetDuration,
            'segments' => $segments
        ];
    }

    /**
     * @param $metadata array
     * @return array
     */
    private function getVideoStreamInfo(array $metadata, RepresentationInterface $representation): array
    {
        $info = [];
//        if (!empty($metadata['stream']['resolutions'][0])) {
//            $general = $metadata['stream']['resolutions'][0];
//            $info['resolution'] = $general['dimension'];
//        }
        $info['width'] =  $representation->getWidth();
        $info['height'] = $representation->getHeight();

        foreach ($metadata['video']['streams'] ?? [] as $streams) {
            if ($streams['codec_type'] === 'video') {
                $info['duration'] = !empty($streams['duration']) ? $streams['duration'] : '0';
                $info['videoCodec'] = !empty($streams['codec_name']) ? $streams['codec_name'] : '';
                $info['videoBitrate'] = !empty($streams['bit_rate']) ? $streams['bit_rate'] : $representation->getKiloBitrate();
                $info['videoFramerate'] = !empty($streams['avg_frame_rate']) ? $streams['avg_frame_rate'] : '0';
            } elseif ($streams['codec_type'] === 'audio') {
                $info['audioCodec'] = !empty($streams['codec_name']) ? $streams['codec_name'] : '' ;
                $info['audioSamplerate'] = !empty($streams['sample_rate']) ? $streams['sample_rate'] : '0';
                $info['audioBitrate'] = !empty($streams['bit_rate']) ? $streams['bit_rate'] : $representation->getAudioKiloBitrate();
            }
        }
        return $info;
    }

    private function setRenditionName($profile)
    {
        $this->renditionName = $profile->getAttribute('width')
            . 'X' . $profile->getAttribute('height')
            . '@' . ($profile->getAttribute('videoBitrate') + $profile->getAttribute('audioBitrate'));
    }

    private function getRenditionName(): string
    {
        return $this->renditionName;
    }


    private function cleanup(): bool
    {
        var_dump("rm -rf {$this->basePath}");
        //return \exec("rm -rf {$this->basePath}");
        return true;
    }

    public function shutdown(): void
    {
        $this->cleanup();
    }
}
