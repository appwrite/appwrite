<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VideoSubtitle extends Model
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
            ->addRule('videoId', [
                'type' => self::TYPE_STRING,
                'description' => 'Video ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('bucketId', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket ID.',
                'default' => '',
                'example' => 'd5fg5ehg1c168g7c',
            ])
            ->addRule('fileId', [
                'type' => self::TYPE_STRING,
                'description' => 'file ID.',
                'default' => '',
                'example' => 'c5fg5emg1c168grr',
            ])
            ->addRule('path', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle path.',
                'default' => '',
                'example' => '640x360@500',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle name.',
                'default' => '',
                'example' => 'English',
            ])
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle code.',
                'default' => '',
                'example' => 'Eng',
            ])
            ->addRule('default', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Subtitle default',
                'default' => '',
                'example' => false,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Subtitle packaging status',
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
        return Response::MODEL_SUBTITLE;
    }
}
