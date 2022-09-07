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
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
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

    private const PROTOCOL_HLS  = 'hls';
    private const PROTOCOL_DASH = 'dash';

    //private string $basePath = '/tmp/';
    private string $basePath = '/usr/src/code/tests/tmp/';

    private string $inDir;

    private string $outDir;

    private string $outPath;

    private string $renditionName;

    private array $audioTracks = [];

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

        $sourceVideo =  Authorization::skip(fn() =>  $this->database->findOne('videos', [
            Query::equal('_uid', [$this->args['videoId']]),
        ]));

        if (empty($sourceVideo)) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        $profile =  Authorization::skip(fn() =>  $this->database->findOne('videos_profiles', [
            Query::equal('_uid', [$this->args['profileId']]),
        ]));

        if (empty($profile)) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $bucket = Authorization::skip(
            fn() => $this->database->getDocument('buckets', $sourceVideo->getAttribute('bucketId'))
        );

        $file = Authorization::skip(
            fn() => $this->database->getDocument('bucket_' . $bucket->getInternalId(), $sourceVideo->getAttribute('fileId'))
        );

        $path = basename($file->getAttribute('path'));
        $inPath = $this->inDir . $path;

        $result = $this->writeData($project, $file);
        if (empty($result)) {
            throw new Exception(Exception::GENERAL_UNKNOWN);
        }

        $ffprobe = FFMpeg\FFProbe::create();
        $ffmpeg = Streaming\FFMpeg::create([
            'timeout' => 0,
            'ffmpeg.threads'  => 12
        ]);

        if (!$ffprobe->isValid($inPath)) {
            throw new Exception('Not an valid Video file "' . $inPath . '"');
        }

        foreach ($ffprobe->streams($inPath)->audios()->getIterator() as $stream) {
            if (!empty($stream->get('tags')['language'])) {
                $this->audioTracks[] = $stream->get('tags')['language'];
            }
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
        $subtitles =  Authorization::skip(fn() =>  $this->database->find('videos_subtitles', [
            Query::equal('videoId', [$this->args['videoId']]),
            Query::equal('status', ['']),
        ]));

        foreach ($subtitles as $subtitle) {
            $subtitle->setAttribute('status', self::STATUS_START);
            Authorization::skip(fn() => $this->database->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                $subtitle
            ));

            $bucket = Authorization::skip(
                fn() => $this->database->getDocument('buckets', $subtitle->getAttribute('bucketId'))
            );

            $file = Authorization::skip(
                fn() => $this->database->getDocument('bucket_' . $bucket->getInternalId(), $subtitle->getAttribute('fileId'))
            );

            $path = basename($file->getAttribute('path'));
            $this->writeData($project, $file);
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $subtitlePath = $this->inDir . $subtitle->getId() . '.vtt';

            if ($ext === 'srt') {
                $srt = new SubripFile($this->inDir . $path);
                $srt->convertTo('webvtt')->save($subtitlePath);
            }

            $subs[] = [
                 'name' => $subtitle->getAttribute('name'),
                 'code' => $subtitle->getAttribute('code'),
                 'path' => $subtitlePath,
            ];
        }

        $query = Authorization::skip(function () use ($profile) {
            return $this->database->createDocument('videos_renditions', new Document([
               'videoId'  => $this->args['videoId'],
               'profileId' => $profile->getId(),
               'name'      => $this->getRenditionName(),
               'startedAt' => DateTime::now(),
               'status'    => self::STATUS_START,
               'protocol'  => $profile->getAttribute('protocol'),
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
            $format->on('progress', function ($video, $format, $percentage) use ($query) {
                if ($percentage % 3 === 0) {
                    $query->setAttribute('progress', (string)$percentage);
                    Authorization::skip(fn() => $this->database->updateDocument(
                        'videos_renditions',
                        $query->getId(),
                        $query
                    ));
                }
            });

            $general = $this->transcode($profile->getAttribute('protocol'), $video, $format, $representation, $subs);
            if (!empty($general)) {
                foreach ($general as $key => $value) {
                    $query->setAttribute($key, (string)$value);
                }
            }

            if ($profile->getAttribute('protocol') === self::PROTOCOL_HLS) {
                $streams = $this->getHlsSegmentsUrls($this->outDir . 'master.m3u8');
                foreach ($streams as $stream) {
                    $m3u8 = $this->getHlsSegments($this->outDir . $stream['path']);
                    if (!empty($m3u8['segments'])) {
                        foreach ($m3u8['segments'] as $segment) {
                            Authorization::skip(function () use ($segment, $project, $query, $renditionPath, $stream) {
                                return $this->database->createDocument('videos_renditions_segments', new Document([
                                    'renditionId' => $query->getId(),
                                    'streamId' => (int)$stream['id'],
                                    'fileName' => $segment['fileName'],
                                    'path' => $renditionPath,
                                    'duration' => $segment['duration'],
                                ]));
                            });
                        }
                    }

                    $query->setAttribute('metadata', json_encode(['hls' => $streams]));
                    $query->setAttribute('targetDuration', $m3u8['targetDuration']);
                }
            } else {
                $mpd = $this->getDashSegments($this->outPath . '.mpd');
                if (!empty($mpd['segments'])) {
                    foreach ($mpd['segments'] as $segment) {
                        Authorization::skip(function () use ($segment, $project, $query, $renditionPath) {
                            return $this->database->createDocument('videos_renditions_segments', new Document([
                                'renditionId' => $query->getId(),
                                'streamId' => $segment['streamId'],
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
            $query->setAttribute('endedAt', DateTime::now());
            Authorization::skip(fn() => $this->database->updateDocument('videos_renditions', $query->getId(), $query));

            foreach ($subtitles ?? [] as $subtitle) {
                if ($profile->getAttribute('protocol') === 'hls') {
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
                    $query->setAttribute('progress', '100');
                    $query->setAttribute('status', self::STATUS_UPLOADING);
                    $query->setAttribute('path', $renditionPath);
                    Authorization::skip(fn() => $this->database->updateDocument('videos_renditions', $query->getId(), $query));
                    $start = 1;
                }
                //@unlink($this->outDir . $fileName);
            }

            $query->setAttribute('status', self::STATUS_READY);
            Authorization::skip(fn() => $this->database->updateDocument('videos_renditions', $query->getId(), $query));
        } catch (\Throwable $th) {
            $query->setAttribute('metadata', json_encode([
            'code' => $th->getCode(),
            'message' => substr($th->getMessage(), 0, 255),
            ]));

            $query->setAttribute('status', self::STATUS_ERROR);
            Authorization::skip(fn() => $this->database->updateDocument('videos_renditions', $query->getId(), $query));
            throw new Exception($th->getMessage(), 500, Exception::GENERAL_UNKNOWN);
        }
    }

    /**
     * @param string $protocol
     * @param $video Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @param array $subtitles
     * @return string|array
     */
    private function transcode(string $protocol, Media $video, StreamFormat $format, Representation $representation, array $subtitles): string | array
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

        $segmentSize = 8;

        if ($protocol === self::PROTOCOL_DASH) {
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
            ->setAudioTracks($this->audioTracks)
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
            $streamId = -1;
            while (($line = fgets($handle)) !== false) {
                $line =  str_replace([",","\r","\n"], "", $line);
                if (str_contains($line, "<AdaptationSet")) {
                    $streamId++;
                }

                if (!str_contains($line, "SegmentURL") && !str_contains($line, "Initialization")) {
                    $metadata .= $line . PHP_EOL;
                } else {
                    $segments[] = [
                        'isInit' => str_contains($line, "Initialization") ? 1 : 0,
                        'streamId' => $streamId,
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
                $line =  str_replace(['"'], '', $line);
                $attributes = explode(',', $line);
                $language = null;
                foreach ($attributes as $attribute) {
                    if (str_contains($attribute, "LANGUAGE")) {
                        $parts = explode('=', $attribute);
                        $language = $parts[1];
                    }
                }
                $end = strpos($line, 'm3u8');
                if ($end !== false) {
                    $start = strpos($line, $this->args['videoId']);
                    if ($start !== false) {
                        $path = substr($line, $start, ($end - $start) + 4);
                        $parts = explode('_', $path);
                        $tmp = [
                            'id' => $parts[1],
                            'type' => str_contains($line, "TYPE=AUDIO") ? 'audio' : 'video',
                            'path' => $path
                        ];

                        if (!empty($language)) {
                            $tmp ['language'] = $language;
                        }

                        $files[] = $tmp;
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

    /**
     * @param $project Document
     * @param $file Document
     * @return boolean
     */
    private function writeData(Document $project, Document $file): bool
    {


        $fullPath = $file->getAttribute('path');
        $path = basename($file->getAttribute('path'));

        if (
            !empty($file->getAttribute('openSSLCipher')) ||
            !empty($file->getAttribute('algorithm', ''))
        ) {
            $data = $this->getFilesDevice($project->getId())->read($fullPath);

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

            $algorithm = $file->getAttribute('algorithm', 'none');
            switch ($algorithm) {
                case 'zstd':
                    $compressor = new Zstd();
                    $data = $compressor->decompress($data);
                    break;
                case 'gzip':
                    $compressor = new GZIP();
                    $data = $compressor->decompress($data);
                    break;
            }

            $result = $this->getFilesDevice(
                $project->getId()
            )->write($this->inDir . $path, $data, $file->getAttribute('mimeType'));
        } else {
            $result = $this->getFilesDevice(
                $project->getId()
            )->transfer($fullPath, $this->inDir . $path, $this->getFilesDevice($project->getId()));
        }

        return $result;
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
