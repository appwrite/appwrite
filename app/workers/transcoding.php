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
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Captioning\Format\SubripFile;

require_once __DIR__ . '/../init.php';

Console::title('Transcoding V1 Worker');
Console::success(APP_NAME . ' transcoding worker v1 has started');

class TranscodingV1 extends Worker
{
    /**
     * Rendition Status
     */
    const STATUS_START     = 'started';
    const STATUS_END       = 'ended';
    const STATUS_UPLOADING = 'uploading';
    const STATUS_READY     = 'ready';
    const STATUS_ERROR     = 'error';

    const STREAM_HLS = 'hls';
    const STREAM_MPEG_DASH = 'mpeg-dash';

    const BASE_URL_ENDPOINT = 'http://127.0.0.1/v1/videos';

    //protected string $basePath = '/tmp/';
    protected string $basePath = '/usr/src/code/tests/tmp/';

    protected string $inDir;

    protected string $outDir;

    protected string $outPath;

    protected string $renditionName;

    protected Database $database;

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
        $data = $this->getFilesDevice($project->getId())->read($file->getAttribute('path'));
        $fileName = basename($file->getAttribute('path'));
        $inPath = $this->inDir . $fileName;
        $collection = 'videos_renditions';

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

        $ffprobe = FFMpeg\FFProbe::create([]);
        $ffmpeg = Streaming\FFMpeg::create([]);

        if (!$ffprobe->isValid($inPath)) {
            throw new Exception('Not an valid FFMpeg file "' . $inPath . '"');
        }

        //Delete prev rendition
        $queries = [
            new Query('videoId', Query::TYPE_EQUAL, [$sourceVideo->getId()]),
            new Query('profileId', Query::TYPE_EQUAL, [$profile->getId()])
        ];

        $rendition = Authorization::skip(fn() => $this->database->findOne($collection, $queries));
        if (!empty($rendition)) {
            Authorization::skip(fn() => $this->database->deleteDocument($collection, $rendition->getId()));
            $deviceFiles = $this->getVideoDevice($project->getId());
            if (!empty($rendition['path'])) {
                $deviceFiles->deletePath($rendition['path']);
            }
        }

            $general = $this->getVideoSourceInfo($ffprobe->streams($inPath));
        if (!empty($general)) {
            foreach ($general as $key => $value) {
                $sourceVideo->setAttribute($key, $value);
            }

            Authorization::skip(fn() => $this->database->updateDocument(
                'videos',
                $sourceVideo->getId(),
                $sourceVideo
            ));
        }

        $video = $ffmpeg->open($inPath);
        $this->setRenditionName($profile);

        $subs = [];
        $subtitles = Authorization::skip(fn () => $this->database->find('videos_subtitles', [
            new Query('status', Query::TYPE_EQUAL, ['']),
            new Query('videoId', Query::TYPE_EQUAL, [$this->args['videoId']])
        ], 12, 0, [], ['ASC']));

        foreach ($subtitles as $subtitle) {
            $subtitle->setAttribute('status', self::STATUS_START);
            $subtitle->setAttribute('path', $this->getRenditionName());
            Authorization::skip(fn() => $this->database->updateDocument(
                'videos_subtitles',
                $subtitle->getId(),
                $subtitle
            ));

            $subtitleBucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $subtitle->getAttribute('bucketId')));
            $subtitleFile = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $subtitleBucket->getInternalId(), $subtitle->getAttribute('fileId')));
            $subtitleData = $this->getFilesDevice($project->getId())->read($subtitleFile->getAttribute('path'));
            $subtitleFileName = basename($subtitleFile->getAttribute('path'));

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
            $ext = pathinfo($subtitleFileName, PATHINFO_EXTENSION);

            if ($ext === 'srt') {
                $srt = new SubripFile($this->inDir . $subtitleFileName);
                $srt->convertTo('webvtt')->save($this->inDir . $this->args['videoId'] . '.vtt');
            }

            $subs[] = [
                 'name' => $subtitle->getAttribute('name'),
                 'code' => $subtitle->getAttribute('code'),
                 'path' => $this->inDir . $this->args['videoId'] . '.vtt',
            ];
        }
        try {
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
        } catch (DuplicateException $exception) {
            throw new Exception('Video  rendition already exists', 409, Exception::VIDEO_RENDITION_ALREADY_EXISTS);
        }


        try {
            $representation = (new Representation())->
            setKiloBitrate($profile->getAttribute('videoBitrate'))->
            setAudioKiloBitrate($profile->getAttribute('audioBitrate'))->
            setResize($profile->getAttribute('width'), $profile->getAttribute('height'));

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
                    $query->setAttribute($key, $value);
                }
            }

            if ($profile['stream'] === 'hls') {
                $m3u8 = $this->parseM3u8($this->outPath . '_' . $representation->getHeight() . 'p.m3u8');
                if (!empty($m3u8['segments'])) {
                    foreach ($m3u8['segments'] as $segment) {
                        Authorization::skip(function () use ($segment, $project, $query) {
                            return $this->database->createDocument('videos_renditions_segments', new Document([
                                'renditionId' => $query->getId(),
                                'type' => 0,
                                'fileName' => $segment['fileName'],
                                'path' => $this->getVideoDevice($project->getId())->getPath($this->args['videoId']) . '/' . $this->getRenditionName() . '/',
                                'duration' => $segment['duration'],
                            ]));
                        });
                    }
                }
                $query->setAttribute('targetDuration', $m3u8['targetDuration']);

            } else {

                $mpd = $this->parseMpd($this->outPath . '.mpd');
                if (!empty($mpd['segments'])) {
                    foreach ($mpd['segments'] as $segment) {
                        Authorization::skip(function () use ($segment, $project, $query) {
                            return $this->database->createDocument('videos_renditions_segments', new Document([
                                'renditionId' => $query->getId(),
                                'representationId' => $segment['representationId'],
                                'fileName' => $segment['fileName'],
                                'path' => $this->getVideoDevice($project->getId())->getPath($this->args['videoId']) . '/' . $this->getRenditionName() . '/',
                                'isInit' => $segment['isInit'],
                                ]));
                        });
                    }
                }
                if (!empty($mpd['metadata'])) {
                    $query->setAttribute('metadata', json_encode($mpd['metadata']));
                }
            }

            $query->setAttribute('status', self::STATUS_END);
            $query->setAttribute('endedAt', time());
            Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));

            if (!empty($subtitles)) {
                foreach ($subtitles as $subtitle) {
                    if ($profile['stream'] === 'hls') {
                        $m3u8 = $this->parseM3u8($this->outPath . '_subtitles_' . $subtitle['code'] . '.m3u8');
                        foreach ($m3u8['segments'] as $segment) {
                            Authorization::skip(function () use ($segment, $project, $subtitle) {
                                return $this->database->createDocument('videos_subtitles_segments', new Document([
                                    'subtitleId'  =>  $subtitle->getId(),
                                    'fileName'  => $segment['fileName'],
                                    'path'  => $this->getVideoDevice($project->getId())->getPath($this->args['videoId']) . '/' ,
                                    'duration' => $segment['duration'],
                                ]));
                            });
                        }
                        $subtitle->setAttribute('targetDuration', $m3u8['targetDuration']);
                    }

                    $subtitle->setAttribute('status', self::STATUS_READY);
                    Authorization::skip(fn() => $this->database->updateDocument(
                        'videos_subtitles',
                        $subtitle->getId(),
                        $subtitle
                    ));
                }
            }
         /** Upload & remove files **/
            $start = 0;
            $fileNames = scandir($this->outDir);

            foreach ($fileNames as $fileName) {
                if ($fileName === '.' || $fileName === '..') {
                    //str_contains($fileName, '.json')) {
                    continue;
                }

                $devicePath  = $this->getVideoDevice($project->getId())->getPath($this->args['videoId']);
                $data = $this->getFilesDevice($project->getId())->read($this->outDir . $fileName);
                $to = $devicePath . '/' . $this->getRenditionName() . '/';
                if (str_contains($fileName, "_subtitles_") || str_contains($fileName, ".vtt")) {
                    $to = $devicePath . '/';
                }

                $this->getVideoDevice($project->getId())->write($to .  $fileName, $data, \mime_content_type($this->outDir . $fileName));
                if ($start === 0) {
                    $query->setAttribute('status', self::STATUS_UPLOADING);
                    $query->setAttribute('path', $devicePath . '/' . $this->getRenditionName());
                    Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));
                    $start = 1;
                }

                //$metadata=[];
                //$chunksUploaded = $deviceFiles->upload($file, $path, -1, 1, $metadata);
                //var_dump($chunksUploaded);
                // if (empty($chunksUploaded)) {
                //  throw new Exception('Failed uploading file', 500, Exception::GENERAL_SERVER_ERROR);
                //}
                // }

                //@unlink($this->outDir . $fileName);
            }

            $query->setAttribute('status', self::STATUS_READY);
            Authorization::skip(fn() => $this->database->updateDocument($collection, $query->getId(), $query));
        } catch (\Throwable $th) {
            $query->setAttribute('metadata', json_encode([
            'code' => $th->getCode(),
            'message' => substr($th->getMessage(), 0, 2048),
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

        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1'
        ];

        $segmentSize = 10;

        if ($stream === 'mpeg-dash') {
                $dash = $video->dash()
                ->setFormat($format)
                ->setSegDuration($segmentSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath);

                return $this->getVideoStreamInfo($dash->metadata()->export(), $representation);
            ;
        }

        $hls = $video->hls();

        foreach ($subtitles as $subtitle) {
            $sub = new HLSSubtitle($subtitle['path'], $subtitle['name'], $subtitle['code']);
            $sub->default();
            $hls->subtitle($sub);
        }

        $hls->setFormat($format)
            ->setHlsTime($segmentSize)
            ->setHlsAllowCache(false)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->save($this->outPath);

        return $this->getVideoStreamInfo($hls->metadata()->export(), $representation);
    }
    /**
     * @param string $path
     * @return array
     */
    private function parseMpd(string $path): array
    {
        $segments = [];
        $metadata = null;
        $representationId = 0;
        $handle = fopen($path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line =  str_replace([",","\r","\n"], "", $line);
                if (str_contains($line, "contentType=\"audio\"")) {
                    $representationId++;
                }

                if (!str_contains($line, "SegmentURL") && !str_contains($line, "Initialization")) {
                    $metadata .= $line . PHP_EOL;
                } else {
                    $segments[] = [
                        'isInit' => str_contains($line, "Initialization") ? 1 : 0,
                        'representationId' => $representationId,
                        'fileName' => str_replace(["<SegmentURL media=\"","<Initialization sourceURL=\"", "\"/>", " "], "", $line),
                    ];
                }
            }
            var_dump($segments);
            fclose($handle);
        }

        $xml = simplexml_load_string($metadata);

        return [
            'metadata' =>  json_decode(json_encode((array)$xml), true),
            'segments' => $segments
        ];
    }

    /**
     * @param string $path
     * @return array
     */
    private function parseM3u8(string $path): array
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
     * @param $streams StreamCollection
     * @return array
     */
    private function getVideoSourceInfo(StreamCollection $streams): array
    {
            return [
                'duration' => !empty($streams->videos()) ? $streams->videos()->first()->get('duration') : '0',
                'height' => !empty($streams->videos()) ? $streams->videos()->first()->get('height') : 0,
                'width' => !empty($streams->videos()) ? $streams->videos()->first()->get('width') : 0,
                'videoCodec'   => !empty($streams->videos()) ? $streams->videos()->first()->get('codec_name') . ',' . $streams->videos()->first()->get('codec_tag_string') : '',
                'videoFramerate' => !empty($streams->videos()) ? $streams->videos()->first()->get('avg_frame_rate') : '',
                'videoBitrate' =>  !empty($streams->videos()) ? (int)$streams->videos()->first()->get('bit_rate') : 0,
                'audioCodec' =>  !empty($streams->audios()) ? $streams->audios()->first()->get('codec_name') . ',' . $streams->audios()->first()->get('codec_tag_string') : '',
                'audioSamplerate' => !empty($streams->audios()) ? (int)$streams->audios()->first()->get('sample_rate') : 0,
                'audioBitrate'   =>  !empty($streams->audios()) ? (int)$streams->audios()->first()->get('bit_rate') : 0,
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
        if (!empty($metadata['video']['streams'])) {
            foreach ($metadata['video']['streams'] as $streams) {
                if ($streams['codec_type'] === 'video') {
                    $info['duration'] = !empty($streams['duration']) ? $streams['duration'] : '0';
                    $info['videoCodec'] = !empty($streams['codec_name']) ? $streams['codec_name'] . ',' . $streams['codec_tag_string'] : '';
                    $info['videoBitrate'] = !empty($streams['bit_rate']) ? (int)$streams['bit_rate'] : $representation->getKiloBitrate() * 1024;
                    $info['videoFramerate'] = !empty($streams['avg_frame_rate']) ? $streams['avg_frame_rate'] : '';
                } elseif ($streams['codec_type'] === 'audio') {
                    $info['audioCodec'] = !empty($streams['codec_name']) ? $streams['codec_name'] . ',' . $streams['codec_tag_string'] : '' ;
                    $info['audioSamplerate'] = !empty($streams['sample_rate']) ? (int)$streams['sample_rate'] : 0;
                    $info['audioBitrate'] = !empty($streams['bit_rate']) ? (int)$streams['bit_rate'] : $representation->getAudioKiloBitrate() * 1024;
                }
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
