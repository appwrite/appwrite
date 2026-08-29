<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class VideoSubtitle extends Model
{
    /**
     * `embedded` is derived, not stored: extracted tracks are the rows with no
     * backing storage file.
     */
    public function filter(Document $document): Document
    {
        return $document->setAttribute('embedded', empty($document->getAttribute('fileId', '')));
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Subtitle creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Subtitle update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('videoId', [
                'type' => self::TYPE_STRING,
                'description' => 'Video ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('bucketId', [
                'type' => self::TYPE_STRING,
                'description' => 'Storage bucket ID holding the subtitle file.',
                'default' => '',
                'example' => 'd5fg5ehg1c168g7c',
            ])
            ->addRule('fileId', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle file ID.',
                'default' => '',
                'example' => 'c5fg5emg1c168grr',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle display name.',
                'default' => '',
                'example' => 'English',
            ])
            // ISO 639-2 three-letter code, validated against the locale-languages config.
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle ISO 639-2 language code.',
                'default' => '',
                'example' => 'eng',
            ])
            ->addRule('default', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is this the default subtitle track?',
                'default' => false,
                'example' => false,
            ])
            ->addRule('embedded', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Was this track auto-extracted from the source container? Extracted tracks have no backing file; extraction runs once per video, so a deleted extracted track is not re-created.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('targetDuration', [
                'type' => self::TYPE_STRING,
                'description' => 'Longest segment duration in seconds.',
                'default' => '',
                'example' => '93',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Packaging status: one of `waiting`, `started`, `ready` or `error`.',
                'default' => '',
                'example' => 'ready',
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
        return 'Video subtitle';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VIDEO_SUBTITLE;
    }
}
