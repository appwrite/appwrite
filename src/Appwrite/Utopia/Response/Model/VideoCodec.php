<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VideoCodec extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Codec ID.',
                'default' => '',
                'example' => 'h264',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Codec display name.',
                'default' => '',
                'example' => 'H.264',
            ])
            ->addRule('outputs', [
                'type' => self::TYPE_STRING,
                'description' => 'Streaming output formats this codec may be packaged into.',
                'default' => [],
                'example' => ['hls', 'dash', 'cmaf'],
                'array' => true,
            ])
        ;
    }

    public function getName(): string
    {
        return 'Video codec';
    }

    public function getType(): string
    {
        return Response::MODEL_VIDEO_CODEC;
    }
}
