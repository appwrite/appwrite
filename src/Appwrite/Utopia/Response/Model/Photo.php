<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Photo extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Photo ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Photo creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Photo update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID the photo belongs to.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Photo file size in bytes.',
                'default' => 0,
                'example' => 17890,
            ])
            ->addRule('mimeType', [
                'type' => self::TYPE_STRING,
                'description' => 'Photo file mime type.',
                'default' => '',
                'example' => 'image/png',
            ]);
    }

    public function getName(): string
    {
        return 'Photo';
    }

    public function getType(): string
    {
        return Response::MODEL_PHOTO;
    }
}
