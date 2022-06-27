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
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'File SIZE.',
                'default' => '',
                'example' => 3567790,
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
        return 'Video entity';
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
