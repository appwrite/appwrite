<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Rendition extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'ID.',
                'default' => null,
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('videoId', [
                'type' => self::TYPE_STRING,
                'description' => 'Video ID.',
                'default' => null,
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('profileId', [
                'type' => self::TYPE_STRING,
                'description' => 'profile ID.',
                'default' => null,
                'example' => 'd5fg5ehg1c168g7c',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition name.',
                'default' => null,
                'example' => '720P',
            ])
            ->addRule('startedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Transcoding time started in Unix timestamp.',
                'default' => 0,
                'example' => 1592981220,
            ])
            ->addRule('endedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Transcoding time ended in Unix timestamp.',
                'default' => 0,
                'example' => 1592981290,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition transcoding status',
                'default' => null,
                'example' => 'ready',
            ])
            ->addRule('progress', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition transcoding progress',
                'default' => 0,
                'example' => 88,
            ])
            ->addRule('output', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition output type',
                'default' => null,
                'example' => 'hls',
            ])
            ->addRule('duration', [
                'type' => self::TYPE_STRING,
                'description' => 'Video duration.',
                'default' => 0,
                'example' => '92.739989',
            ])
            ->addRule('width', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video width.',
                'default' => 0,
                'example' => 300,
            ])
            ->addRule('height', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video height.',
                'default' => 0,
                'example' => 400,
            ])
            ->addRule('videoCodec', [
                'type' => self::TYPE_STRING,
                'description' => 'Video codec.',
                'default' => null,
                'example' => 'h264,avc1',
            ])
            ->addRule('videoBitrate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video bitrate.',
                'default' => 0,
                'example' => 564790,
            ])
            ->addRule('videoFramerate', [
                'type' => self::TYPE_STRING,
                'description' => 'Video frame rate.',
                'default' => 0,
                'example' => '231947377/4638947',
            ])
            ->addRule('audioCodec', [
                'type' => self::TYPE_STRING,
                'description' => 'Audio codec.',
                'default' => null,
                'example' => 'aac,mp4a',
            ])
            ->addRule('audioBitrate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Audio bitrate.',
                'default' => 0,
                'example' => 127999,
            ])
            ->addRule('audioSamplerate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Audio sample rate.',
                'default' => 0,
                'example' => 44100,
            ])

        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Video rendition';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_RENDITION;
    }
}
