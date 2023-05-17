<?php

use Appwrite\Event\Delete;
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
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Compression\Algorithms\Zstd;
use Captioning\Format\SubripFile;
use Utopia\Storage\Device\Local;

require_once __DIR__ . '/../init.php';

Console::title('Video V1 Worker');
Console::success(APP_NAME . ' video worker v1 has started');

class VideosV1 extends Worker
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
    private string $inPath;
    private string $outDir;
    private string $outPath;
    private string $renditionName;
    private Database $database;
    private Document $video;
    private Document $subtitle;
    private string $action;
    private Document $profile;
    private Document $project;
    private Document $file;
    private Document $bucket;
    private FFProbe $ffprobe;
    private FFMpeg $ffmpeg;


    public function getName(): string
    {
        return "Video v1";
    }

    public function init(): void
    {
        $this->video     =  new Document($this->args['video'] ?? []);
        $this->profile   =  new Document($this->args['profile'] ?? []);
        $this->project   =  new Document($this->args['project'] ?? []);
        $this->subtitle  =  new Document($this->args['subtitle'] ?? []);

        $this->action   =  $this->args['action'];
        $this->basePath .= uniqid();
        $this->inDir    =  $this->basePath . '/in/';
        $this->outDir   =  $this->basePath . '/out/';
        @mkdir($this->inDir, 0755, true);
        @mkdir($this->outDir, 0755, true);
        $this->outPath = $this->outDir . $this->video->getId();
    }


    public function run(): void
    {

        $this->database = $this->getProjectDB($this->project->getId());
        $this->bucket = $this->database->getDocument('buckets', $this->video->getAttribute('bucketId'));
        $this->file = $this->database->getDocument('bucket_' . $this->bucket->getInternalId(), $this->video->getAttribute('fileId'));
        $path = basename($this->file->getAttribute('path'));
        $this->inPath = $this->inDir . $path;

        match ($this->action) {
            'timeline'  => $this->createTimeLine(),
            'subtitle'  => $this->createSubtitle(),
            default     => $this->createOutput(),
        };
    }


    /**
     * Create timeline
     *
     * @throws Authorization
     * @throws Structure
     * @throws Throwable
     */
    private function createTimeLine(): void
    {

        /*
         * Title:             PlayerJS Thumbnails & WebVTT Creator
         * URI:               https://playerjs.com/docs/q=thumbnailsphpwebvtt
         * Version:           1.0
         * Author:            Playerjs.com
         * Author URI:        https://playerjs.com
         * License:           GPL-2.0+
         * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
         * Text Domain:       playerjs
        */

        $this->download($this->project, $this->file);

        if (empty($this->video->getAttribute('duration'))) {
            $this->createInfo();
        }

        if (empty($this->video->getAttribute('videoBitRate'))) {
            return;
        }

        $interval = 2;
        $ranges = [
            ['from' => 120, 'to' => 600, 'interval' => 5],
            ['from' => 600, 'to' => 1800, 'interval' => 10],
            ['from' => 1800, 'to' => 3600, 'interval' => 20],
            ['from' => 3600, 'to' => 99999, 'interval' => 30],
        ];

        foreach ($ranges as $range) {
            if (
                $this->video->getAttribute('duration') > $range['from'] &&
                $this->video->getAttribute('duration') <= $range['to']
            ) {
                $interval = $range['interval'];
                break;
            }
        }

        $timeline['aspect'] = $this->video->getAttribute('width') / $this->video->getAttribute('height');
        $timeline['width'] = 160;
        $timeline['height'] = round($timeline['width'] / $timeline['aspect']);
        $timeline['size'] = '5x5';

        $cmd = [
            '/usr/bin/ffmpeg',
            '-i ' .  $this->inPath,
            '-hide_banner',
            '-loglevel error',
            '-vsync vfr',
            '-vf \'select=isnan(prev_selected_t)+gte(t-prev_selected_t\,' . $interval . '),scale=' . $timeline['width'] . ':' . $timeline['height'] . ',tile=' . $timeline['size'] . '\'',
            '-qscale:v 3',
            $this->outDir . 'sprite%d.jpg',
        ];
        $result = shell_exec(implode(" ", $cmd));

        /**
         * Vtt creation*
         */
        if ($result !== false) {
            $size = explode('x', $timeline['size']);
            $counter = 0;
            $images = ceil((($this->video->getAttribute('duration') / 1000) / $interval) / ($size[0] * $size[1]));
            $data = "WEBVTT";
            $path = $this->getVideoDevice($this->project->getId())->getPath($this->video->getId()) . '/timeline/';
            for ($image = 1; $image <= $images; $image++) {
                $sprite = $this->database->createDocument('videos_previews', new Document([
                    'videoId' => $this->video->getId(),
                    'videoInternalId' => $this->video->getInternalId(),
                    'type' => 'sprite',
                    'name' => 'sprite' . $image . '.jpg',
                    'path' => $path,
                ]));

                $url = TMP_HOST . 'v1/videos/' . $this->video->getId() . '/preview/' . $sprite->getId() . '/';
                for ($col = 0; $col < $size[0]; $col++) {
                    for ($row = 0; $row < $size[1]; $row++) {
                        $data .= "\n" . gmdate("H:i:s", $counter * $interval) . " --> " . gmdate("H:i:s", ($counter + 1) * $interval) . "\n" . $url . "#xywh=" . ($row * $timeline['width']) . "," . ($col * $timeline['height']) . "," . $timeline['width'] . "," . $timeline['height'];
                        $counter++;
                    }
                }
            }

            if ($counter > 0) {
                /**
                 * Upload vtt
                 */
                $this->getVideoDevice($this->project->getId())->write(
                    $this->getVideoDevice($this->project->getId())->getPath($this->video->getId() . '/timeline/timeline.vtt'),
                    $data,
                    'text/vtt'
                );

                console::info('Uploading timeline vtt');

                /**
                 * Upload sprites
                 */
                $dir = new DirectoryIterator($this->outDir);
                foreach ($dir as $fileinfo) {
                    if (!$fileinfo->isDot()) {
                        console::info('Uploading ' . $fileinfo->getFilename());
                        $this->getVideoDevice($this->project->getId())->write(
                            $path . $fileinfo->getFilename(),
                            (new Local('/'))->read($this->outDir . $fileinfo->getFilename()),
                            mime_content_type($this->outDir . $fileinfo->getFilename())
                        );
                    }
                }
            }
        }
    }

    /**
     * Create subtitle
     *
     * @throws Authorization
     * @throws Structure
     * @throws Throwable
     */
    private function createSubtitle(): void
    {

        if (empty($this->video->getAttribute('duration'))) {
            $this->download($this->project, $this->file);
            $this->createInfo();
        }

        $segments = $this->database->find('videos_subtitles_segments', [
            Query::equal('subtitleInternalId', [$this->subtitle->getInternalId()]),
        ]);

        foreach ($segments as $segment) {
            $this->database->deleteDocument('videos_subtitles_segments', $segment->getId());
        }

        $this->subtitle->setAttribute('status', self::STATUS_START);
        $this->database->updateDocument('videos_subtitles', $this->subtitle->getId(), $this->subtitle);

        $bucket = $this->database->getDocument('buckets', $this->subtitle->getAttribute('bucketId'));
        $file = $this->database->getDocument('bucket_' . $bucket->getInternalId(), $this->subtitle->getAttribute('fileId'));
        $path = basename($file->getAttribute('path'));

        $this->download($this->project, $file);

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $subtitlePath = $this->inDir . $this->subtitle->getId() . '.vtt';

        if ($ext === 'srt') {
            $srt = new SubripFile($this->inDir . $path);
            $srt->convertTo('webvtt')->save($subtitlePath);
        }

        $path = $this->getVideoDevice($this->project->getId())->getPath($this->video->getId()) . '/subtitles/';
        $this->database->createDocument('videos_subtitles_segments', new Document([
                'subtitleId' => $this->subtitle->getId(),
                'subtitleInternalId' => $this->subtitle->getInternalId(),
                'fileName' => $this->subtitle->getId() . '.vtt',
                'path' => $path,
                'duration' => (string)number_format(($this->video->getAttribute('duration') / 1000), 1),
            ]));

        /**
         * Upload subtitle
         */
        console::info('Uploading ' . $this->subtitle->getId() . '.vtt');
        $this->getVideoDevice($this->project->getId())->write(
            $path . $this->subtitle->getId() . '.vtt',
            (new Local('/'))->read($this->inDir . $this->subtitle->getId() . '.vtt'),
            'text/vtt'
        );

        $this->subtitle->setAttribute('targetDuration', (string)number_format(($this->video->getAttribute('duration') / 1000), 1));
        $this->subtitle->setAttribute('status', self::STATUS_READY);
        $this->subtitle->setAttribute('path', $path);
        $this->database->updateDocument('videos_subtitles', $this->subtitle->getId(), $this->subtitle);
    }

    /**
     * Create output
     *
     * @throws Authorization
     * @throws Exception
     * @throws Throwable
     * @throws Structure
     */
    private function createOutput(): void
    {

        $this->download($this->project, $this->file);
        if (empty($this->video->getAttribute('duration'))) {
            $this->createInfo();
        }

        /**
         * FFMpeg init
         */
        $this->ffprobe = FFProbe::create();
        $this->ffmpeg = FFMpeg::create([
            'timeout' => 0,
            'ffmpeg.threads' => 4
        ]);

        if (!$this->ffprobe->isValid($this->inPath)) {
            console::error('Not an valid Video file "' .  $this->inPath . '"');
        }

        $media = $this->ffmpeg->open($this->inPath);

        $this->renditionName = $this->profile->getAttribute('width')
            . 'X' . $this->profile->getAttribute('height')
            . '@' . ($this->profile->getAttribute('videoBitRate') + $this->profile->getAttribute('audioBitRate'));


        $query = $this->database->createDocument('videos_renditions', new Document([
            'videoId' => $this->video->getId(),
            'videoInternalId' => $this->video->getInternalId(),
            'profileId' => $this->profile->getId(),
            'profileInternalId' => $this->profile->getInternalId(),
            'name' => $this->renditionName,
            'startedAt' => DateTime::now(),
            'status' => self::STATUS_START,
            'width' => $this->profile->getAttribute('width'),
            'height' => $this->profile->getAttribute('height'),
            'videoBitRate' => $this->profile->getAttribute('videoBitRate'),
            'audioBitRate' => $this->profile->getAttribute('audioBitRate'),
            'output' => $this->args['output'],
        ]));

        $this->send($query);

        try {
            $representation = (new Representation())
                ->setKiloBitRate($this->profile->getAttribute('videoBitRate'))
                ->setAudioKiloBitRate($this->profile->getAttribute('audioBitRate'))
                ->setResize($this->profile->getAttribute('width'), $this->profile->getAttribute('height'));

            console::info('Output video id:' . $this->video->getId() . PHP_EOL .
                'Output name: ' . $this->file->getAttribute('name') . PHP_EOL .
                'Output width: ' . $this->profile->getAttribute('width') . PHP_EOL .
                'Output height: ' . $this->profile->getAttribute('height') . PHP_EOL .
                'Output video BitRate:' . $this->profile->getAttribute('videoBitRate') . PHP_EOL .
                'Output audio BitRate:' . $this->profile->getAttribute('audioBitRate') . PHP_EOL .
                'Output:' . $this->args['output'] . PHP_EOL);

            $format = new Streaming\Format\X264();
            $format->on('progress', function ($media, $format, $percentage) use ($query) {
                if ($percentage % 3 === 0) {
                    $query->setAttribute('progress', (string)$percentage);
                    $this->database->updateDocument('videos_renditions', $query->getId(), $query);
                    $this->send($query, 'update');
                }
            });

            $this->transcode($media, $format, $representation);

            unset($media);

            //exec('/usr/bin/ffmpeg -y -i /usr/src/code/tests/tmp/637f59c88f9ff0fe3b1f/637e1b82aeab8980400e/in/637f59ab5bce0e36d05e.mp4 -c:v libx264 -c:a aac -bf 1 -keyint_min 25 -g 250 -sc_threshold 40 -use_timeline 0 -use_template 0 -seg_duration 10 -hls_playlist 0 -f dash -dn -sn -vf scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1 -b_strategy 1 -bf 3 -force_key_frames "expr:gte(t,n_forced*2)" -map 0 -s:v:0 1024x576 -b:v:0 2538k -b:a:0 128k -strict -2 -threads 12 /usr/src/code/tests/tmp/637f59c88f9ff0fe3b1f/637e1b82aeab8980400e/out/637f59c88f9ff0fe3b1f.mpd2>&1', $o, $v);
            //var_dump($o);
            //var_dump($v);

            $path = $this->getVideoDevice($this->project->getId())->getPath($this->video->getId()) . '/' . $this->renditionName . '-' . $query->getId() . '/';

            if ($this->args['output'] === self::OUTPUT_HLS) {
                $streams = $this->getHlsSegmentsUrls($this->outDir . 'master.m3u8');
                foreach ($streams as $stream) {
                    $m3u8 = $this->getSegments($this->outDir . $stream['path']);
                    if (!empty($m3u8['segments'])) {
                        foreach ($m3u8['segments'] as $segment) {
                            $this->database->createDocument('videos_renditions_segments', new Document([
                                'renditionId' => $query->getId(),
                                'renditionInternalId' => $query->getInternalId(),
                                'streamId' => (int)$stream['id'],
                                'fileName' => $segment['fileName'],
                                'path' => $path,
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
                            'renditionInternalId' => $query->getInternalId(),
                            'streamId' => $segment['streamId'],
                            'fileName' => $segment['fileName'],
                            'path' => $path,
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

            console::info('Rendition ' . $query->getId() . ' conversion, done');

            /**
             * Upload
             */
            $dir = new DirectoryIterator($this->outDir);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot()) {
                    $data = (new Local('/'))->read($this->outDir . $fileinfo->getFilename());

                    console::info('Uploading ' . $fileinfo->getFilename());

                    $this->getVideoDevice($this->project->getId())->write(
                        $path . $fileinfo->getFilename(),
                        $data,
                        mime_content_type($this->outDir . $fileinfo->getFilename())
                    );

                    if ($fileinfo->key() === 0) {
                        $query->setAttribute('progress', '100');
                        $query->setAttribute('status', self::STATUS_UPLOADING);
                        $query->setAttribute('path', $path);
                        $this->database->updateDocument('videos_renditions', $query->getId(), $query);
                        $this->send($query, 'update');
                    }
                }
            }

            $query->setAttribute('status', self::STATUS_READY);
            $this->database->updateDocument('videos_renditions', $query->getId(), $query);
            $this->send($query, 'update');
        } catch (\Throwable $th) {
            $query->setAttribute('metadata', json_encode([
                'code' => $th->getCode(),
                'message' => substr($th->getMessage(), 0, 255),
            ]));

            $query->setAttribute('status', self::STATUS_ERROR);
            $this->database->updateDocument('videos_renditions', $query->getId(), $query);
            $this->send($query, 'update');

            console::error('Error video id:' . $this->video->getId() . PHP_EOL
                . 'Message: ' . $th->getMessage() . PHP_EOL
                . 'File: ' . $th->getFile() . PHP_EOL
                . 'line: ' . $th->getLine() . PHP_EOL);
        }
    }


    /**
     * Create media Info
     *
     * @throws Authorization
     * @throws Structure
     * @throws Throwable
     */
    private function createInfo(): void
    {

        $mediaInfo = new MediaInfo();
        $mediaInfoContainer = $mediaInfo->getInfo($this->inPath);
        $general = $mediaInfoContainer->getGeneral();
        $this->video
            ->setAttribute('duration', $general->has('duration') ? $general->get('duration')->getMilliseconds() : 0)
            ->setAttribute('format', $general->has('format') ? $general->get('format')->getShortName() : '');

        foreach ($mediaInfoContainer->getVideos() ?? [] as $video) {
            $this->video
                ->setAttribute('height', $video->has('height') ? $video->get('height')->getAbsoluteValue() : 0)
                ->setAttribute('width', $video->has('width') ? $video->get('width')->getAbsoluteValue() : 0)
                ->setAttribute('aspectRatio', $video->has('display_aspect_ratio') ? $video->get('display_aspect_ratio')->getTextValue() : '')
                ->setAttribute('videoFormat', $video->has('format') ? $video->get('format')->getShortName() : '')
                ->setAttribute('videoFormatProfile', $video->has('format_profile') ? $video->get('format_profile') : '')
                ->setAttribute('videoFrameRate', $video->has('frame_rate') ? strval($video->get('frame_rate')->getAbsoluteValue()) : '')
                ->setAttribute('videoFrameRateMode', $video->has('frame_rate_mode') ? $video->get('frame_rate_mode')->getFullName() : '')
                ->setAttribute('videoBitRate', $video->has('bit_rate') ? $video->get('bit_rate')->getAbsoluteValue() : 0);
        }

        foreach ($mediaInfoContainer->getAudios() ?? [] as $audio) {
            $this->video
                ->setAttribute('audioFormat', $audio->has('format') ? strval($audio->get('format')->getShortName()) : '')
                ->setAttribute('audioSampleRate', $audio->has('sampling_rate') ? strval($audio->get('sampling_rate')->getAbsoluteValue()) : '')
                ->setAttribute('audioBitRate', $audio->has('bit_rate') ? $audio->get('bit_rate')->getAbsoluteValue() : 0);
        }

        console::info('Input video id: ' . $this->video->getId() . PHP_EOL .
            'Input name: ' . $this->file->getAttribute('name') . PHP_EOL .
            'Input width: ' . $this->video->getAttribute('width') . ' px' . PHP_EOL .
            'Input height: ' . $this->video->getAttribute('height') . ' px' . PHP_EOL .
            'Input duration: ' . ($this->video->getAttribute('duration') / 1000) . ' Sec' . PHP_EOL .
            'Input size: ' . ($this->video->getAttribute('size') / 1024 / 1024) . ' MiB' . PHP_EOL .
            'Input videoBitRate: ' . ($this->video->getAttribute('videoBitRate') / 1000) . ' kb/s' . PHP_EOL .
            'Input audioBitRate: ' . ($this->video->getAttribute('audioBitRate') / 1000) . ' kb/s' . PHP_EOL);

        $this->database->updateDocument(
            'videos',
            $this->video->getId(),
            new document(
                array_filter((array)$this->video, fn($value) => !is_null($value))
            ),
        );
    }

    /**
     * Transcode
     *
     * @param $media Media
     * @param $format StreamFormat
     * @param $representation Representation
     * @return void
     */
    private function transcode(Media $media, StreamFormat $format, Representation $representation): void
    {

        $additionalParams = [
            '-dn',
            '-sn',
            '-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1', //keep frame size
            '-crf',  '22', // constant rate factor
            '-bf', '3', // bframe
            '-force_key_frames', 'expr:gte(t,n_forced*2)' //enforce strict key frame
        ];

        $segmentSize = 6;
        if ($this->args['output'] === self::OUTPUT_DASH) {
               $media->dash()
                ->setFormat($format)
                ->setSegDuration($segmentSize)
                ->addRepresentation($representation)
                ->setAdditionalParams($additionalParams)
                ->save($this->outPath);
               return;
        }

        $media->hls()
            ->setFormat($format)
            ->setHlsTime($segmentSize)
            ->addRepresentation($representation)
            ->setAdditionalParams($additionalParams)
            ->save($this->outPath);
    }

    /**
     * Get HLS|DASH segments
     *
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
     * Get HLS segments urls
     *
     * @param string $path
     * @return array
     */
    private function getHlsSegmentsUrls(string $path): array
    {

        $files = [];
        $handle = fopen($path, "r");
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $tmp = [];
                $line = str_replace(['"'], '', $line);
                $attributes = explode(',', $line);
                foreach ($attributes as $key => $attribute) {
                    $parts = explode('=', $attribute);
                    switch (true) {
                        case str_contains($parts[0], 'LANGUAGE'):
                            $attr['language'] = $parts[1];
                            break;
                        case str_contains($parts[0], 'BANDWIDTH'):
                            $attr['bandwidth'] = $parts[1];
                            break;
                        case str_contains($parts[0], 'RESOLUTION'):
                            $attr['resolution'] = $parts[1];
                            break;
                        case str_contains($parts[0], 'CODECS'):
                            $attr['codecs'] = trim($parts[1], " \n\r\t\v\x00");

                            if (!empty($attributes[$key + 1])) {
                                $attr['codecs'] .= ',' . trim($attributes[$key + 1], " \n\r\t\v\x00");
                            }
                            break;
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
                            'path' => $path
                        ];
                        if (str_contains($line, "TYPE=AUDIO")) {
                            $tmp['type'] = 'audio';
                            if (!empty($attr['language'])) {
                                $tmp['language'] = $attr['language'];
                            }
                        } else {
                            $tmp['type'] = 'video';
                            if (!empty($attr['resolution'])) {
                                $tmp['resolution'] = $attr['resolution'];
                            }
                            if (!empty($attr['bandwidth'])) {
                                $tmp['bandwidth'] = $attr['bandwidth'];
                            }
                            if (!empty($attr['codecs'])) {
                                $tmp['codecs'] = $attr['codecs'];
                            }
                        }
                    }
                    $files[] = $tmp;
                }
            }
            fclose($handle);
        }
        return $files;
    }

    /**
     * Send
     *
     * @param string $action
     * @param Document $payload
     * @throws Exception|\Exception
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
     * Download
     *
     * @param $project Document
     * @param $file Document
     * @throws \Exception
     */
    private function download(Document $project, Document $file)
    {

        $fullPath = $file->getAttribute('path');
        $path = basename($file->getAttribute('path'));

        console::info('Downloading file: ' . $path . ' to local storage ' . $this->inDir);

        $local = new Local('/');
        try {
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
                $local->
                    write($this->inDir . $path, $data, $file->getAttribute('mimeType'));
            } else {
                $this->getFilesDevice(
                    $project->getId()
                )->transfer($fullPath, $this->inDir . $path, $local);
            }
        } catch (\Throwable $th) {
            console::error($th->getMessage() . ' on file: ' . $th->getFile() . ' line: ' . $th->getLine);
        }
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
