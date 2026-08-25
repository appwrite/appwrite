<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Videos extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'bucketId',
        'fileId',
        'size',
        'status',
        'format',
        'duration',
        'width',
        'height',
        'videoCodec',
        'videoBitRate',
        'audioCodec',
        'audioBitRate',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('videos', self::ALLOWED_ATTRIBUTES);
    }
}
