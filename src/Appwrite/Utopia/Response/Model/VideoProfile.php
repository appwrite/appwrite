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
                'description' => 'ID.',
                'default' => null,
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Video profile name.',
                'default' => null,
                'example' => '360P',
            ])
            ->addRule('videoBitrate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Video bitrate.',
                'default' => 0,
                'example' => 3,
            ])
            ->addRule('audioBitrate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Audio bitrate.',
                'default' => 0,
                'example' => 3,
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

            ->addRule('output', [
                'type' => self::TYPE_STRING,
                'description' => 'Video out type.',
                'default' => null,
                'example' => 'dash',
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
        return Response::MODEL_PROFILE;
    }
}
