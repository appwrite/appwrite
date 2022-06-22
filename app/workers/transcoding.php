<?php

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Streaming\Format\StreamFormat;
use Streaming\Media;
use Streaming\Representation;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Utopia\Storage\Compression\Algorithms\GZIP;

require_once __DIR__ . '/../init.php';

Console::title('Transcoding V1 Worker');
Console::success(APP_NAME . ' transcoding worker v1 has started');

class TranscodingV1 extends Worker
{
    const HLS_BASE_URL = '';

    protected string $basePath = '/tmp/';

    protected string $inDir;

    protected string $outDir;

    protected string $outPath;

    protected Database $database;


    public function getName(): string
    {
        return "Transcoding";
    }


    public function init(): void
    {

        $this->basePath .=   $this->args['fileId'] . '/' . $this->args['profileId'];
        $this->inDir  =  $this->basePath . '/in/';
        $this->outDir =  $this->basePath . '/out/';
        @mkdir($this->inDir, 0755, true);
        @mkdir($this->outDir, 0755, true);
        $this->outPath = $this->outDir . $this->args['fileId']; /** TODO figure a way to write dir tree without this **/
    }

    public function run(): void
    {
        $project = new Document($this->args['project']);
        $this->database = $this->getProjectDB($project->getId());
        $profile = Authorization::skip(fn() => $this->database->findOne('video_profiles', [new Query('_uid', Query::TYPE_EQUAL, [$this->args['profileId']])]));

        if($profile->isEmpty()){
            throw new Exception('No profile found');
         }

        $user = new Document($this->args['user'] ?? []);
        $bucket = Authorization::skip(fn() => $this->database->getDocument('buckets', $this->args['bucketId']));


        if ($bucket->getAttribute('permission') === 'bucket') {
            $file = Authorization::skip(fn() => $this->database->getDocument('bucket_' . $bucket->getInternalId(), $this->args['fileId']));
        } else {
            $file = $this->database->getDocument('bucket_' . $bucket->getInternalId(), $this->args['fileId']);
        }

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

        //TODO Can you retranscode?
        $queries = [
            new Query('bucketId', Query::TYPE_EQUAL, [$this->args['bucketId']]),
            new Query('fileId', Query::TYPE_EQUAL, [$this->args['fileId']])
        ];

        $renditions = Authorization::skip(fn() => $this->database->find($collection, $queries, 12, 0, [], ['ASC']));
        if (!empty($renditions)) {
            foreach ($renditions as $rendition) {
                Authorization::skip(fn() => $this->database->deleteDocument($collection, $rendition->getId()));
            }

            $deviceFiles = $this->getVideoDevice($project->getId());
            $devicePath = $deviceFiles->getPath($this->args['fileId']);
            $devicePath = str_ireplace($deviceFiles->getRoot(), $deviceFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $devicePath);
            $deviceFiles->deletePath($devicePath);
        }

        $sourceInfo = $this->getVideoInfo($ffprobe->streams($inPath));
        $video = $ffmpeg->open($inPath);
            foreach (['hls', 'dash'] as $stream) {
                $query = Authorization::skip(function () use ($collection, $profile, $stream) {
                    return $this->database->createDocument($collection, new Document([
                        'bucketId'  => $this->args['bucketId'],
                        'fileId'    => $this->args['fileId'],
                        'profileId' => $profile->getId(),
                        'name'      => $profile->getAttribute('name'),
                        'startedAt' => time(),
                        'status'    => 'started',
                        'stream'    => $stream,
                    ]));
                });

                try {
                    $representation = (new Representation())->
                    setKiloBitrate($profile->getAttribute('videoBitrate'))->
                    setAudioKiloBitrate($profile->getAttribute('audioBitrate'))->
                    setResize( $profile->getAttribute('width'), $profile->getAttribute('height'));

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


                    list($general, $metadata) = $this->transcode($stream, $video, $format, $representation);
                    if (!empty($metadata)) {
                        $query->setAttribute('metadata', json_encode($metadata));
                    }

                    $query->setAttribute('status', 'ended');
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

                        $deviceFiles = $this->getVideoDevice($project->getId());
                        $devicePath = $deviceFiles->getPath($this->args['fileId']);
                        $devicePath = str_ireplace($deviceFiles->getRoot(), $deviceFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $devicePath);
                        $data = $this->getFilesDevice($project->getId())->read($this->outDir . $fileName);
                        $this->getVideoDevice($project->getId())->write($devicePath . DIRECTORY_SEPARATOR . $profile->getAttribute('name') . DIRECTORY_SEPARATOR .  $fileName, $data, \mime_content_type($this->outDir . $fileName));
                        var_dump($devicePath . DIRECTORY_SEPARATOR . $profile->getAttribute('name') . DIRECTORY_SEPARATOR .  $fileName);
                        if ($start === 0) {
                            $query->setAttribute('status', 'uploading');
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

                        @unlink($this->outDir . $fileName);
                    }

                    $query->setAttribute('status', 'ready');
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

                    $query->setAttribute('status', 'error');
                    Authorization::skip(fn() => $this->database->updateDocument(
                        $collection,
                        $query->getId(),
                        $query
                    ));
                }
            }
        }

    /**
     * @param $metadata array
     * @return array
     */
    private function getMetadataExport(array $metadata): array
    {
        $info = [];

        if (!empty($metadata['stream']['resolutions'][0])) {
            $general = $metadata['stream']['resolutions'][0];
            var_dump($general);
            $parts = explode("x", $general);
            $info['width']   = $parts['0'];
            $info['height']  = $parts['1'];
        }

        if (!empty($metadata['video']['streams'])) {
            foreach ($metadata['video']['streams'] as $streams) {
                if ($streams['codec_type'] === 'video') {
                    $info['duration']       = $streams['duration'];
                    $info['videoCodec']     = $streams['codec_name'] . ',' . $streams['codec_tag_string'];
                    $info['videoBitRate']   = $streams['bit_rate'];
                    $info['videoFrameRate'] = $streams['avg_frame_rate'];
                } elseif ($streams['codec_type'] === 'audio') {
                    $info['audioCodec']     = $streams['codec_name'] . ',' . $streams['codec_tag_string'];
                    $info['audioBitRate']   = $streams['sample_rate'];
                    $info['audioSamplRate'] = $streams['bit_rate'];
                }
            }
        }

        return $info;
    }

    /**
     * @param string $stream
     * @param $video Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @return array
     */
    private function transcode(string $stream, Media $video, StreamFormat $format, Representation $representation): string | array
    {

        $additionalParams = [
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1'
        ];

        $segementSize = 10;

        if ($stream === 'dash') {
                $dash = $video->dash()
                ->setFormat($format)
                ->setSegDuration($segementSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath);
                var_dump($this->outPath);
                    $xml = simplexml_load_string(
                        file_get_contents($this->outDir . $this->args['fileId'] . '.mpd')
                    );

                return [
                        $this->getMetadataExport($dash->metadata()->export()),
                        ['dash'   => !empty($xml) ? json_decode(json_encode((array)$xml), true) : []],
                      ];
        }


        $hls = $video->hls()
            ->setFormat($format)
            ->setHlsTime($segementSize)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->setHlsBaseUrl(self::HLS_BASE_URL)
            ->save($this->outPath);

        return [
            $this->getMetadataExport($hls->metadata()->export()), []
        ];
    }


    /**
     * @param $streams StreamCollection
     * @return array
     */
    private function getVideoInfo(StreamCollection $streams): array
    {
        return [
            'duration' => $streams->videos()->first()->get('duration'),
            'height' => $streams->videos()->first()->get('height'),
            'width' => $streams->videos()->first()->get('width'),
            'frameRate' => $streams->videos()->first()->get('r_frame_rate'),
            'bitrateKb' => $streams->videos()->first()->get('bit_rate') / 1000,
            'bitrateMb' =>  $streams->videos()->first()->get('bit_rate') / 1000 / 1000,
        ];
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
