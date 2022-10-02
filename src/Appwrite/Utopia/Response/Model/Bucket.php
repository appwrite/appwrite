<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Bucket extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Bucket creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Bucket update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket permissions. [Learn more about permissions](/docs/permissions).',
                'default' => [],
                'example' => ['read("any")'],
                'array' => true,
            ])
            ->addRule('fileSecurity', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether file-level security is enabled. [Learn more about permissions](/docs/permissions).',
                'default' => false,
                'example' => true,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket name.',
                'default' => '',
                'example' => 'Documents',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Bucket enabled.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('maximumFileSize', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum file size supported.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('allowedFileExtensions', [
                'type' => self::TYPE_STRING,
                'description' => 'Allowed file extensions.',
                'default' => [],
                'example' => ['jpg', 'png'],
                'array' => true
            ])
            ->addRule('compression', [
                'type' => self::TYPE_STRING,
                'description' => 'Compression algorithm choosen for compression. Will be one of ' . COMPRESSION_TYPE_NONE . ', [' . COMPRESSION_TYPE_GZIP . '](https://en.wikipedia.org/wiki/Gzip), or [' . COMPRESSION_TYPE_ZSTD . '](https://en.wikipedia.org/wiki/Zstd), or [' . COMPRESSION_TYPE_SNAPPY . '](https://en.wikipedia.org/wiki/Snappy_(compression)).',
                'default' => '',
                'example' => 'gzip',
                'array' => false
            ])
            ->addRule('encryption', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Bucket is encrypted.',
                'default' => true,
                'example' => false,
            ])
            ->addRule('antivirus', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Virus scanning is enabled.',
                'default' => true,
                'example' => false,
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
        return 'Bucket';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_BUCKET;
    }
}
