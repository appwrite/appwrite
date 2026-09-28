<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Avatar extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Photo upload ID. Pass this back in the x-appwrite-id header to continue a chunked upload.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Photo upload creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Photo upload update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID the photo belongs to.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('sizeOriginal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Photo file original size in bytes.',
                'default' => 0,
                'example' => 17890,
            ])
            ->addRule('sizeActual', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Photo file actual stored size in bytes. Zero until all chunks are uploaded.',
                'default' => 0,
                'example' => 17890,
            ])
            ->addRule('mimeType', [
                'type' => self::TYPE_STRING,
                'description' => 'Photo file mime type.',
                'default' => '',
                'example' => 'image/png',
            ])
            ->addRule('chunksTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of chunks available.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('chunksUploaded', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of chunks uploaded.',
                'default' => 0,
                'example' => 1,
            ]);
    }

    public function getName(): string
    {
        return 'Avatar';
    }

    public function getType(): string
    {
        return Response::MODEL_AVATAR;
    }
}
