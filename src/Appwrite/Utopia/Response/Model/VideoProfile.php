<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VideoProfile extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Video profile ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Video profile creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Video profile update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Video profile name.',
                'default' => '',
                'example' => '360p',
            ])
            ->addRule('videoBitRate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Target video bitrate in kilobits per second.',
                'default' => 0,
                'example' => 890,
            ])
            ->addRule('audioBitRate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Target audio bitrate in kilobits per second.',
                'default' => 0,
                'example' => 64,
            ])
            ->addRule('width', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Target video width in pixels.',
                'default' => 0,
                'example' => 640,
            ])
            ->addRule('height', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Target video height in pixels.',
                'default' => 0,
                'example' => 360,
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
        return 'Video profile';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VIDEO_PROFILE;
    }
}
