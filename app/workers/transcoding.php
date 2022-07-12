<?php

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Streaming\Format\StreamFormat;
use Streaming\HLSSubtitle;
use Streaming\Media;
use Streaming\Representation;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
    const STATUS_TRANSCODE_START     = 'started';
    const STATUS_TRANSCODE_END       = 'ended';
    const STATUS_UPLOADING           = 'uploading';
    const STATUS_PACKAGE_END         = 'ready';
    const STATUS_ERROR               = 'error';

    const STREAM_HLS = 'hls';
    const STREAM_MPEG_DASH = 'mpeg-dash';

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
        $this->outPath = $this->outDir . $this->args['videoId']; /** TODO figure a way to write dir tree without this **/
    }

    public function run(): void
    {
        $project = new Document($this->args['project']);
        $this->database = $this->getProjectDB($project->getId());

        $sourceVideo = Authorization::skip(fn() => $this->database->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['videoId']])]));
        if (empty($sourceVideo)) {
            throw new Exception('Video not found');
        }

        $profile = Authorization::skip(fn() => $this->database->findOne('video_profiles', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['profileId']])]));
        if (empty($profile)) {
            throw new Exception('profile not found');
        }

        $bucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $sourceVideo['bucketId']));
        $file = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $bucket->getInternalId(), $sourceVideo['fileId']));
        $data = $this->getFilesDevice($project->getId())->read($file->getAttribute('path'));
        $fileName = basename($file->getAttribute('path'));
        $inPath = $this->inDir . $fileName;
        $collection = 'video_renditions';

        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
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

        $general = $this->getVideoInfo($ffprobe->streams($inPath));
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
        $subtitles = Authorization::skip(fn () => $this->database->find('video_subtitles', [new Query('videoId', Query::TYPE_EQUAL, [$this->args['videoId']])], 12, 0, [], ['ASC']));
        foreach ($subtitles as $subtitle) {
            $subtitleBucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $subtitle->getAttribute('bucketId')));
            $subtitleFile = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $subtitleBucket->getInternalId(), $subtitle->getAttribute('fileId')));
            $subtitleData = $this->getFilesDevice($project->getId())->read($subtitleFile->getAttribute('path'));
            $subtitleFileName = basename($subtitleFile->getAttribute('path'));

            if (!empty($subtitleFile->getAttribute('openSSLCipher'))) { // Decrypt
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

        $query = Authorization::skip(function () use ($collection, $profile) {
                    return $this->database->createDocument($collection, new Document([
                        'videoId'  => $this->args['videoId'],
                        'profileId' => $profile->getId(),
                        'name'      => $this->getRenditionName(),
                        'startedAt' => time(),
                        'status'    => self::STATUS_TRANSCODE_START,
                        'stream'    => $profile['stream'],
                    ]));
        });

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


            list($general, $metadata) = $this->transcode($profile['stream'], $video, $format, $representation, $subs);
            if (!empty($metadata)) {
                $query->setAttribute('metadata', json_encode($metadata));
            }

            if (!empty($general)) {
                foreach ($general as $key => $value) {
                    $query->setAttribute($key, $value);
                }
            }

            $query->setAttribute('status', self::STATUS_TRANSCODE_END);
            $query->setAttribute('endedAt', time());
            Authorization::skip(fn() => $this->database->updateDocument(
                $collection,
                $query->getId(),
                $query
            ));

         /** Upload & remove files **/
            $start = 0;
            $fileNames = scandir($this->outDir);

            foreach ($fileNames as $fileName) {
                if (
                    $fileName === '.' ||
                    $fileName === '..' ||
                    str_contains($fileName, '.json')
                ) {
                    continue;
                }

                $deviceFiles  = $this->getVideoDevice($project->getId());
                $devicePath   = $deviceFiles->getPath($this->args['videoId']);
                $data = $this->getFilesDevice($project->getId())->read($this->outDir . $fileName);
                $to = $devicePath . '/' . $this->getRenditionName() . '/';
                if (str_contains($fileName, "_subtitles_") || str_contains($fileName, ".vtt")) {
                    $to = $devicePath . '/';
                }

                $this->getVideoDevice($project->getId())->write($to .  $fileName, $data, \mime_content_type($this->outDir . $fileName));
                if ($start === 0) {
                    $query->setAttribute('status', self::STATUS_UPLOADING);
                    $query->setAttribute('path', $devicePath . '/' . $this->getRenditionName());
                    Authorization::skip(fn() => $this->database->updateDocument(
                        $collection,
                        $query->getId(),
                        $query
                    ));
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

            $query->setAttribute('status', self::STATUS_PACKAGE_END);
            Authorization::skip(fn() => $this->database->updateDocument(
                $collection,
                $query->getId(),
                $query
            ));
        } catch (\Throwable $th) {
            $query->setAttribute('metadata', json_encode([
            'code' => $th->getCode(),
            'message' => $th->getMessage(),
            ]));

            $query->setAttribute('status', self::STATUS_ERROR);
            Authorization::skip(fn() => $this->database->updateDocument(
                $collection,
                $query->getId(),
                $query
            ));
        }
    }

    /**
     * @param string $stream
     * @param $video Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @return array
     */
    private function transcode(string $stream, Media $video, StreamFormat $format, Representation $representation, array $subtitles): string | array
    {

        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1'
        ];

        $segementSize = 10;

        if ($stream === 'mpeg-dash') {
                $dash = $video->dash()
                ->setFormat($format)
                ->setSegDuration($segementSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath);

                    $xml = simplexml_load_string(
                        file_get_contents($this->outDir . $this->args['videoId'] . '.mpd')
                    );

                $general = $this->getVideoInfo($dash->metadata()->getVideoStreams());
                $general['width'] = $representation->getWidth();
                $general['height'] = $representation->getHeight();
                return [
                         $general,
                        ['mpeg-dash'   => !empty($xml) ? json_decode(json_encode((array)$xml), true) : []],
                      ];
        }


        $hls = $video->hls();


        foreach ($subtitles as $subtitle) {
            $sub = new HLSSubtitle($subtitle['path'], $subtitle['name'], $subtitle['code']);
            $sub->default();
            $sub->setM3u8Uri($this->getHlsBaseUri(false) .  $this->args['videoId'] . '_subtitles_' . $subtitle['code'] . '.m3u8');
            $hls->subtitle($sub);
        }


        $hls->setFormat($format)
            ->setHlsTime($segementSize)
            ->setHlsAllowCache(false)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->setHlsBaseUrl($this->getHlsBaseUri())
            ->save($this->outPath);

        $general = $this->getVideoInfo($hls->metadata()->getVideoStreams());
        $general['videoBitrate'] = $representation->getKiloBitrate() * 1024;
        $general['audioBitrate'] = $representation->getAudioKiloBitrate() * 1024;
        $general['width'] = $representation->getWidth();
        $general['height'] = $representation->getHeight();

        foreach ($subtitles as $subtitle) {
            $this->rewriteHlsLines($this->outPath . '_subtitles_' . $subtitle['code'] . '.m3u8');
        }

        return [
            $general, []
        ];
    }

    /**
     * @param bool $nest
     * @return string
     */
    private function getHlsBaseUri(bool $nest = true): string
    {
        $uri = 'http://127.0.0.1/v1/video/' . $this->args['videoId'] . '/' . self::STREAM_HLS . '/';

        if (empty($nest)) {
            return $uri;
        }

        return $uri . $this->getRenditionName() . '/';
    }

    private function rewriteHlsLines($path)
    {
        $handle = fopen($path, "r");
        $destination = fopen($path . '_tmp', "w");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $newLine = str_replace(array("\r","\n"), "", $line);
                if (
                    str_contains($line, ".ts") ||
                    str_contains($line, ".vtt") ||
                    str_contains($line, ".m3u8")
                ) {
                    $newLine = $this->getHlsBaseUri(str_contains($line, ".vtt") ?? false) . $newLine;
                }
                fwrite($destination, $newLine . PHP_EOL);
            }
            rename($path . '_tmp', $path);
            fclose($handle);
            fclose($destination);
        }
    }

    /**
     * @param $streams StreamCollection
     * @return array
     */
    private function getVideoInfo(StreamCollection $streams): array
    {
        //var_dump($streams->videos()->first()->get('bit_rate'));
        //var_dump($streams->audios()->first()->get('bit_rate'));
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
