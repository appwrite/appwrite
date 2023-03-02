<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Video extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('bucketId', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('fileId', [
                'type' => self::TYPE_STRING,
                'description' => 'File ID.',
                'default' => '',
                'example' => 'd5fg5ehg1c168g7c',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'File size.',
                'default' => 0,
                'example' => 23647142,
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'Format.',
                'default' => '',
                'example' => 'MPEG-4',
            ])
            ->addRule('aspectRatio', [
                'type' => self::TYPE_STRING,
                'description' => 'Aspect ratio .',
                'default' => '',
                'example' => '16:9',
            ])
            ->addRule('duration', [
                'type' => self::TYPE_STRING,
                'require' => false,
                'description' => 'Video duration.',
                'default' => 0,
                'example' => 92.739989,
            ])
            ->addRule('width', [
                'type' => self::TYPE_INTEGER,
                'require' => false,
                'description' => 'Video width.',
                'default' => 0,
                'example' => 960,
            ])
            ->addRule('height', [
                'type' => self::TYPE_INTEGER,
                'require' => false,
                'description' => 'Video height.',
                'default' => 0,
                'example' => 544,
            ])
            ->addRule('videoFormat', [
                'type' => self::TYPE_STRING,
                'require' => false,
                'description' => 'Video format.',
                'default' => '',
                'example' => 'AVC',
            ])
            ->addRule('videoFormatProfile', [
                'type' => self::TYPE_STRING,
                'require' => false,
                'description' => 'Video format profile.',
                'default' => '',
                'example' => 'Baseline@L3.1',
            ])
             ->addRule('videoBitrate', [
            'type' => self::TYPE_INTEGER,
            'require' => false,
            'description' => 'Video bitrate.',
            'default' => 0,
            'example' => 564790,
             ])
            ->addRule('videoFramerate', [
                'type' => self::TYPE_STRING,
                'require' => false,
                'description' => 'Video frame rate.',
                'default' => 0,
                'example' => '231947377/4638947',
            ])
            ->addRule('audioFormat', [
                'type' => self::TYPE_STRING,
                'require' => false,
                'description' => 'Audio format.',
                'default' => '',
                'example' => 'AAC',
            ])
            ->addRule('audioBitrate', [
                'type' => self::TYPE_INTEGER,
                'require' => false,
                'description' => 'Audio bitrate.',
                'default' => 0,
                'example' => 127999,
            ])
            ->addRule('audioSampleRate', [
                'type' => self::TYPE_INTEGER,
                'require' => false,
                'description' => 'Audio sample rate.',
                'default' => '0',
                'example' => '',
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
        return 'Video';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VIDEO;
    }
}
