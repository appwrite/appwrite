<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class VideoRendition extends Model
{
    /**
     * Stored in kilobits per second, the unit encoders take; returned in bits
     * per second like the probed bitrates on a video.
     */
    public function filter(Document $document): Document
    {
        foreach (['videoBitRate', 'audioBitRate'] as $attribute) {
            $value = $document->getAttribute($attribute);
            if (\is_int($value)) {
                $document->setAttribute($attribute, $value * 1000);
            }
        }

        return $document;
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Rendition creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Rendition update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('videoId', [
                'type' => self::TYPE_STRING,
                'description' => 'Video ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('profileId', [
                'type' => self::TYPE_STRING,
                'description' => 'Video profile ID this rendition was encoded against.',
                'default' => '',
                'example' => 'd5fg5ehg1c168g7c',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition name, derived from its dimensions and bitrate.',
                'default' => '',
                'example' => '1280X720@3679',
            ])
            // VAR_DATETIME columns, filtered through the `datetime` filter.
            ->addRule('startedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transcoding start time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('endedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transcoding end time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('width', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Rendition width in pixels.',
                'default' => 0,
                'example' => 1280,
            ])
            ->addRule('height', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Rendition height in pixels.',
                'default' => 0,
                'example' => 720,
            ])
            ->addRule('videoBitRate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video bitrate in bits per second.',
                'default' => 0,
                'example' => 3551000,
            ])
            ->addRule('audioBitRate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Audio bitrate in bits per second.',
                'default' => 0,
                'example' => 128000,
            ])
            ->addRule('targetDuration', [
                'type' => self::TYPE_STRING,
                'description' => 'Longest segment duration in seconds.',
                'default' => '',
                'example' => '6',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Transcoding status: one of `pending`, `started`, `ended`, `ready`, `error` or `aborted`.',
                'default' => 'pending',
                'example' => 'ready',
                'enum' => ['pending', 'started', 'ended', 'ready', 'error', 'aborted'],
                'enumSDKName' => 'VideoRenditionStatus',
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'description' => 'Why the rendition failed or was aborted. Empty unless `status` is `error` or `aborted`.',
                'default' => '',
                'example' => 'The requested file is not a valid video or audio file (ffprobe: Invalid data found when processing input)',
            ])
            ->addRule('progress', [
                'type' => self::TYPE_STRING,
                'description' => 'Transcoding progress as a percentage.',
                'default' => '',
                'example' => '88',
            ])
            ->addRule('output', [
                'type' => self::TYPE_ENUM,
                'description' => 'Streaming output format: `hls`, `dash`, or `cmaf`.',
                'default' => 'hls',
                'example' => 'hls',
                'enum' => ['hls', 'dash', 'cmaf'],
                'enumSDKName' => 'VideoOutput',
            ])
            ->addRule('codec', [
                'type' => self::TYPE_ENUM,
                'description' => 'Video encode codec snapshotted from the profile at create time: one of `h264`, `hevc`, or `vp9`.',
                'default' => 'h264',
                'example' => 'h264',
                'enum' => ['h264', 'hevc', 'vp9'],
                'enumSDKName' => 'VideoCodec',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Stored rendition size in bytes.',
                'default' => 0,
                'example' => 18350080,
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
        return Response::MODEL_VIDEO_RENDITION;
    }
}
