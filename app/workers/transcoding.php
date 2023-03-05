<?php

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Streaming\FFMpeg;
use FFMpeg\FFProbe;
use Streaming\Format\StreamFormat;
use Streaming\HLSSubtitle;
use Streaming\Media;
use Streaming\Representation;
use Mhor\MediaInfo\MediaInfo;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Captioning\Format\SubripFile;

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

    private const OUTPUT_HLS  = 'hls';
    private const OUTPUT_DASH = 'dash';

    private string $basePath = '/tmp/';
    //private string $basePath = '/usr/src/code/tests/tmp/';

    private string $inDir;

    private string $outDir;

    private string $outPath;

    private string $renditionName;

    private Database $database;

    private Document $video;

    private Document $profile;

    private Document $project;

    private Document $file;

    private Document $bucket;

    private FFProbe $ffprobe;

    private FFMpeg $ffmpeg;

    public function getName(): string
    {
        return "Transcoding v1";
    }

    public function init(): void
    {
        $this->video   = new Document($this->args['video']);
        $this->profile = new Document($this->args['profile']);
        $this->project = new Document($this->args['project']);
        $this->basePath .=   $this->video->getId() . '/' . $this->profile->getId();
        $this->inDir  =  $this->basePath . '/in/';
        $this->outDir =  $this->basePath . '/out/';
        @mkdir($this->inDir, 0755, true);
        @mkdir($this->outDir, 0755, true);
        $this->outPath = $this->outDir . $this->video->getId();
    }

    public function run(): void
    {
        $startTime = time();
        $this->database = $this->getProjectDB($this->project->getId());
        $this->bucket = $this->database->getDocument('buckets', $this->video->getAttribute('bucketId'));
        $this->file = $this->database->getDocument('bucket_' . $this->bucket->getInternalId(), $this->video->getAttribute('fileId'));
        $path = basename($this->file->getAttribute('path'));
        $inPath = $this->inDir . $path;
        $result = $this->write($this->project, $this->file);

        console::info('Transferring video from storage to [' . $this->inDir . ']');

        if (empty($result)) {
            console::error('Storage transfer error');
        }

        $this->renditionName = $this->profile->getAttribute('width')
            . 'X' . $this->profile->getAttribute('height')
            . '@' . ($this->profile->getAttribute('videoBitRate') + $this->profile->getAttribute('audioBitRate'));


        /**
         * FFMpeg init
         */
        $this->ffprobe = FFProbe::create();
        $this->ffmpeg = FFMpeg::create([
            'timeout' => 0,
            'ffmpeg.threads'  => 12
        ]);

        if (!$this->ffprobe->isValid($inPath)) {
            console::error('Not an valid Video file "' . $inPath . '"');
        }

        /**
         * Original asset media info
         */
        $mediaInfo = new MediaInfo();
        $mediaInfoContainer = $mediaInfo->getInfo($inPath);
        $general = $mediaInfoContainer->getGeneral();
        $this->video
            ->setAttribute('duration', strval($general->get('duration')->getMilliseconds()))
            ->setAttribute('format', $general->get('format')->getShortName());

        foreach ($mediaInfoContainer->getVideos() ?? [] as $video) {
            $this->video
                ->setAttribute('height', $video->get('height')->getAbsoluteValue())
                ->setAttribute('width', $video->get('width')->getAbsoluteValue())
                ->setAttribute('aspectRatio', $video->get('display_aspect_ratio')->getTextValue())
                ->setAttribute('videoFormat', $video->get('format')->getShortName())
                ->setAttribute('videoFormatProfile', $video->get('format_profile'))
                ->setAttribute('videoFrameRate', strval($video->get('frame_rate')->getAbsoluteValue()))
                ->setAttribute('videoFrameRateMode', $video->get('frame_rate_mode')->getFullName())
                ->setAttribute('videoBitRate', strval($video->get('bit_rate')->getAbsoluteValue()));
        }

        foreach ($mediaInfoContainer->getAudios() ?? [] as $audio) {
            $this->video
                ->setAttribute('audioFormat', strval($audio->get('format')->getShortName()))
                ->setAttribute('audioSampleRate', strval($audio->get('sampling_rate')->getAbsoluteValue()))
                ->setAttribute('audioBitRate', strval($audio->get('bit_rate')->getAbsoluteValue()));
        }

        console::info('Input video id: ' . $this->video->getId() . PHP_EOL .
            'Input name: ' . $this->file->getAttribute('name') . PHP_EOL .
            'Input width: ' . $this->video->getAttribute('width') . ' px' . PHP_EOL .
            'Input height: ' . $this->video->getAttribute('height')  . ' px' . PHP_EOL .
            'Input duration: ' . ($this->video->getAttribute('duration') / 1000) . ' sec' . PHP_EOL .
            'Input size: ' . ($this->video->getAttribute('size') / 1024 / 1024) . ' MiB' . PHP_EOL .
            'Input videoBitRate: ' . ($this->video->getAttribute('videoBitRate') / 1000) . ' kb/s' . PHP_EOL .
            'Input audioBitRate: ' . ($this->video->getAttribute('audioBitRate') / 1000) . ' kb/s' . PHP_EOL);

        $this->database->updateDocument('videos', $this->video->getId(), $this->video);

        $media = $this->ffmpeg->open($inPath);

        $subs = [];
        $subtitles =  $this->database->find('videos_subtitles', [
            Query::equal('videoId', [$this->video->getId()]),
            Query::equal('status', ['']),
        ]);

        foreach ($subtitles as $subtitle) {
            $subtitle->setAttribute('status', self::STATUS_START);
            $this->database->updateDocument('videos_subtitles', $subtitle->getId(), $subtitle);

            $bucket = $this->database->getDocument('buckets', $subtitle->getAttribute('bucketId'));
            $file   = $this->database->getDocument('bucket_' . $bucket->getInternalId(), $subtitle->getAttribute('fileId'));
            $path = basename($file->getAttribute('path'));
            $this->write($this->project, $file);

            console::info('Transferring subtitle from storage to [' . $this->inDir . ']');

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

        $query = $this->database->createDocument('videos_renditions', new Document([
               'videoId'  =>  $this->video->getId(),
               'profileId' => $this->profile->getId(),
               'name'      => $this->renditionName,
               'startedAt' => DateTime::now(),
               'status'    => self::STATUS_START,
               'output'    => $this->args['output'],
            ]));
        $this->send($query);
        $renditionRootPath = $this->getVideoDevice($this->project->getId())->getPath($this->video->getId()) . '/';
        $renditionPath = $renditionRootPath . $this->renditionName . '-' . $query->getId() .  '/';

        try {
            $representation = (new Representation())
                ->setKiloBitRate($this->profile->getAttribute('videoBitRate'))
                ->setAudioKiloBitRate($this->profile->getAttribute('audioBitRate'))
                ->setResize($this->profile->getAttribute('width'), $this->profile->getAttribute('height'))
            ;

            console::info('Output video id:' . $this->video->getId() . PHP_EOL .
                'Output name: ' . $this->file->getAttribute('name') . PHP_EOL .
                'Output width: ' . $this->profile->getAttribute('width')  . PHP_EOL .
                'Output height: ' . $this->profile->getAttribute('height') . PHP_EOL .
                'Output video BitRate:' . $this->profile->getAttribute('videoBitRate') . PHP_EOL .
                'Output audio BitRate:' . $this->profile->getAttribute('audioBitRate') . PHP_EOL .
                'Output:' . $this->profile->getAttribute('output') . PHP_EOL);

            $format = new Streaming\Format\X264();
            $format->on('progress', function ($media, $format, $percentage) use ($query) {
                if ($percentage % 3 === 0) {
                    $query->setAttribute('progress', (string)$percentage);
                    $this->database->updateDocument('videos_renditions', $query->getId(), $query);
                    $this->send($query, 'update');
                }
            });

            $this->transcode($media, $format, $representation, $subs);

            unset($media);
            //exec('/usr/bin/ffmpeg -y -i /usr/src/code/tests/tmp/637f59c88f9ff0fe3b1f/637e1b82aeab8980400e/in/637f59ab5bce0e36d05e.mp4 -c:v libx264 -c:a aac -bf 1 -keyint_min 25 -g 250 -sc_threshold 40 -use_timeline 0 -use_template 0 -seg_duration 10 -hls_playlist 0 -f dash -dn -sn -vf scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1 -b_strategy 1 -bf 3 -force_key_frames "expr:gte(t,n_forced*2)" -map 0 -s:v:0 1024x576 -b:v:0 2538k -b:a:0 128k -strict -2 -threads 12 /usr/src/code/tests/tmp/637f59c88f9ff0fe3b1f/637e1b82aeab8980400e/out/637f59c88f9ff0fe3b1f.mpd2>&1', $o, $v);
            //var_dump($o);
            //var_dump($v);

            if ($this->args['output'] === self::OUTPUT_HLS) {
                $streams = $this->getHlsSegmentsUrls($this->outDir . 'master.m3u8');
                foreach ($streams as $stream) {
                    $m3u8 = $this->getSegments($this->outDir . $stream['path']);
                    if (!empty($m3u8['segments'])) {
                        foreach ($m3u8['segments'] as $segment) {
                            $this->database->createDocument('videos_renditions_segments', new Document([
                                    'renditionId' => $query->getId(),
                                    'streamId' => (int)$stream['id'],
                                    'fileName' => $segment['fileName'],
                                    'path' => $renditionPath,
                                    'duration' => $segment['duration'],
                                ]));
                        }
                    }

                    $query->setAttribute('metadata', json_encode(['hls' => $streams]));
                    $query->setAttribute('targetDuration', $m3u8['targetDuration']);
                }
            } else {
                $mpd = $this->getSegments($this->outPath . '.mpd');
                if (!empty($mpd['segments'])) {
                    foreach ($mpd['segments'] as $segment) {
                            $this->database->createDocument('videos_renditions_segments', new Document([
                                'renditionId' => $query->getId(),
                                'streamId' => $segment['streamId'],
                                'fileName' => $segment['fileName'],
                                'path' => $renditionPath,
                                'isInit' => $segment['isInit'],
                                ]));
                    }
                }

                if (!empty($mpd['metadata'])) {
                    $query->setAttribute('metadata', json_encode(['mpd' => $mpd['metadata']]));
                }
            }

            $query->setAttribute('status', self::STATUS_END);
            $query->setAttribute('endedAt', DateTime::now());
            $this->database->updateDocument('videos_renditions', $query->getId(), $query);
            $this->send($query, 'update');

            foreach ($subtitles ?? [] as $subtitle) {
                if ($this->profile->getAttribute('output') === 'hls') {
                    $m3u8 = $this->getHlsSegments($this->outPath . '_subtitles_' . $subtitle['code'] . '.m3u8');
                    foreach ($m3u8['segments'] ?? [] as $segment) {
                            $this->database->createDocument('videos_subtitles_segments', new Document([
                                'subtitleId'  =>  $subtitle->getId(),
                                'fileName'  => $segment['fileName'],
                                'path'  => $renditionRootPath ,
                                'duration' => $segment['duration'],
                            ]));
                    }
                    $subtitle->setAttribute('targetDuration', $m3u8['targetDuration']);
                } else {
                    $this->getFilesDevice($this->project->getId())->transfer($this->inDir . $subtitle->getId() . '.vtt', $this->outDir . $subtitle->getId() . '.vtt', $this->getFilesDevice($this->project->getId()));
                }

                $subtitle->setAttribute('status', self::STATUS_READY);
                $subtitle->setAttribute('path', $renditionRootPath);
                $this->database->updateDocument('videos_subtitles', $subtitle->getId(), $subtitle);
            }

            console::info('Rendition ' . $query->getId() . ' conversion, done');

            /** Upload & cleanup **/
            $start = 0;
            $fileNames = scandir($this->outDir);
            foreach ($fileNames as $fileName) {
                if ($fileName === '.' || $fileName === '..') {
                    //str_contains($fileName, '.json')) {
                    continue;
                }

                $data = $this->getFilesDevice($this->project->getId())->read($this->outDir . $fileName);
                $to = $renditionPath;
                if (str_contains($fileName, "_subtitles_") || str_contains($fileName, ".vtt")) {
                    $to = $renditionRootPath;
                }

                $this->getVideoDevice($this->project->getId())->write($to .  $fileName, $data, \mime_content_type($this->outDir . $fileName));
                if ($start === 0) {
                    $query->setAttribute('progress', '100');
                    $query->setAttribute('status', self::STATUS_UPLOADING);
                    $query->setAttribute('path', $renditionPath);
                    $this->database->updateDocument('videos_renditions', $query->getId(), $query);
                    $this->send($query, 'update');
                    $start = 1;

                    console::info('Uploading to [' . $to . ']');
                }
                @unlink($this->outDir . $fileName);
            }

            $query->setAttribute('status', self::STATUS_READY);
            $this->database->updateDocument('videos_renditions', $query->getId(), $query);
            $this->send($query, 'update');

            Console::warning('Job total time ' . (microtime(true) - $startTime) . ' seconds');
        } catch (\Throwable $th) {
            $query->setAttribute('metadata', json_encode([
                'code' => $th->getCode(),
                'message' => substr($th->getMessage(), 0, 255),
            ]));

            $query->setAttribute('status', self::STATUS_ERROR);
            $this->database->updateDocument('videos_renditions', $query->getId(), $query);
            $this->send($query, 'update');

            console::error($th->getMessage());
        }
    }

    /**
     * @param $media Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @param array $subtitles
     * @return void
     */
    private function transcode(Media $media, StreamFormat $format, Representation $representation, array $subtitles): void
    {

        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1',
            '-b_strategy', '1',
            '-bf', '3',
            '-force_key_frames', 'expr:gte(t,n_forced*2)' //enforce strict key frame
        ];

        $segmentSize = 10;

        if ($this->args['output'] === self::OUTPUT_DASH) {
               $media->dash()
                ->setFormat($format)
                ->setSegDuration($segmentSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath);
        }

        $hls = $media->hls();

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
    }

    /**
     * @param string $path
     * @return array
     */
    private function getSegments(string $path): array
    {

        $segments = [];
        if ($this->args['output'] === self::OUTPUT_DASH) {
            $metadata = null;
            $handle = fopen($path, "r");
            if ($handle) {
                $streamId = -1;
                while (($line = fgets($handle)) !== false) {
                    $line = str_replace([",", "\r", "\n"], "", $line);
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
                    $start = strpos($line, $this->video->getId());
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
     * Send realtime updates
     *
     * @param string $action
     * @param Document $payload
     * @throws Exception
     */
    private function send(Document $payload, string $action = 'create')
    {
        unset($payload['metadata']);

        $allEvents = Event::generateEvents('videos.[videoId].renditions.[renditionId].' . $action, [
            'videoId'     => $payload['videoId'],
            'renditionId' => $payload->getId()
        ]);

        $payload->setAttribute('$permissions', $this->bucket->getAttribute('fileSecurity', false)
            ? \array_merge($this->bucket->getAttribute('$permissions'), $this->file->getAttribute('$permissions'))
            : $this->bucket->getAttribute('$permissions'));

        $target = Realtime::fromPayload(
            event: $allEvents[0],
            payload: $payload
        );

        Realtime::send(
            projectId: 'console',
            payload: $payload->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles']
        );
    }

    /**
     * Write files
     *
     * @param $project Document
     * @param $file Document
     * @return boolean
     */
    private function write(Document $project, Document $file): bool
    {

        $fullPath = $file->getAttribute('path');
        $path = basename($file->getAttribute('path'));

        if (
            !empty($file->getAttribute('openSSLCipher')) ||
            $file->getAttribute('algorithm', 'none') !== 'none'
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

    private function cleanup(): bool
    {
        $stdout = '';
        $stderr = '';
        $stdin = '';

        return Console::execute("rm -rf {$this->basePath}", $stdin, $stdout, $stderr, 3) === 0;
    }

    public function shutdown(): void
    {
        $result = $this->cleanup();
        if (!$result) {
            Console::error('Failed Removing files from [' . $this->basePath . ']');
        }
        Console::info('Removing files from [' . $this->basePath . ']');
    }
}
