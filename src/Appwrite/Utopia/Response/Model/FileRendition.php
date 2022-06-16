<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class FileRendition extends Model
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
            ->addRule('renditionId', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Rendition ID.',
                'default' => '',
                'example' => 3,
            ])
            ->addRule('renditionName', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition name.',
                'default' => '',
                'example' => '720P',
            ])
            ->addRule('startedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Transcoding time started in Unix timestamp.',
                'default' => 0,
                'example' => 1592981220,
            ])
            ->addRule('endedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Transcoding time ended in Unix timestamp.',
                'default' => 0,
                'example' => 1592981290,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition transcoding status',
                'default' => '',
                'example' => 'ready',
            ])
            ->addRule('progress', [
                'type' => self::TYPE_STRING,
                'description' => 'Rendition trascoding progress',
                'default' => 0,
                'example' => 88,
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
        return 'File rendition';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FILE_RENDITION;
    }
}
