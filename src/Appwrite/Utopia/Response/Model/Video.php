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
                'description' => 'Video ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Video creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Video update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('bucketId', [
                'type' => self::TYPE_STRING,
                'description' => 'Storage bucket ID holding the source file.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('fileId', [
                'type' => self::TYPE_STRING,
                'description' => 'Source file ID.',
                'default' => '',
                'example' => 'd5fg5ehg1c168g7c',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Video name.',
                'default' => '',
                'example' => 'Product demo',
            ])
            ->addRule('previewId', [
                'type' => self::TYPE_STRING,
                'description' => 'Preview image ID, taken from the sprite timeline.',
                'default' => '',
                'example' => 'd5fg5ehg56c168g5b',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Source file size in bytes.',
                'default' => 0,
                'example' => 23647142,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Source status: one of `pending`, `downloading`, `ready`, `removed`, `error` or `aborted`.',
                'default' => '',
                'example' => 'ready',
                'enum' => ['pending', 'downloading', 'ready', 'removed', 'error', 'aborted'],
            ])
            ->addRule('chunksTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of chunks in the source download.',
                'default' => 0,
                'example' => 8,
            ])
            ->addRule('chunksUploaded', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of source chunks downloaded so far.',
                'default' => 0,
                'example' => 3,
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'Container format.',
                'default' => '',
                'example' => 'MPEG-4',
            ])
            // Milliseconds, matching the VAR_INTEGER column the metadata probe writes.
            ->addRule('duration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video duration in milliseconds.',
                'default' => 0,
                'example' => 92810,
            ])
            ->addRule('width', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video width in pixels.',
                'default' => 0,
                'example' => 960,
            ])
            ->addRule('height', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video height in pixels.',
                'default' => 0,
                'example' => 544,
            ])
            ->addRule('aspectRatio', [
                'type' => self::TYPE_STRING,
                'description' => 'Video aspect ratio.',
                'default' => '',
                'example' => '16:9',
            ])
            ->addRule('videoCodec', [
                'type' => self::TYPE_STRING,
                'description' => 'Video codec.',
                'default' => '',
                'example' => 'h264',
            ])
            ->addRule('videoFormat', [
                'type' => self::TYPE_STRING,
                'description' => 'Video format.',
                'default' => '',
                'example' => 'AVC',
            ])
            ->addRule('videoFormatProfile', [
                'type' => self::TYPE_STRING,
                'description' => 'Video format profile.',
                'default' => '',
                'example' => 'Baseline@L3.1',
            ])
            ->addRule('videoBitRate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video bitrate in bits per second.',
                'default' => 0,
                'example' => 564790,
            ])
            ->addRule('videoFrameRate', [
                'type' => self::TYPE_STRING,
                'description' => 'Video frame rate.',
                'default' => '',
                'example' => '25.000',
            ])
            ->addRule('videoFrameRateMode', [
                'type' => self::TYPE_STRING,
                'description' => 'Video frame rate mode.',
                'default' => '',
                'example' => 'CFR',
            ])
            ->addRule('audioCodec', [
                'type' => self::TYPE_STRING,
                'description' => 'Audio codec.',
                'default' => '',
                'example' => 'aac',
            ])
            ->addRule('audioFormat', [
                'type' => self::TYPE_STRING,
                'description' => 'Audio format.',
                'default' => '',
                'example' => 'AAC',
            ])
            ->addRule('audioBitRate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Audio bitrate in bits per second.',
                'default' => 0,
                'example' => 127999,
            ])
            // Stored as a string because mediainfo reports values such as "48.0 kHz".
            ->addRule('audioSampleRate', [
                'type' => self::TYPE_STRING,
                'description' => 'Audio sample rate.',
                'default' => '',
                'example' => '48000',
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
