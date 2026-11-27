<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class VideoRenditions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'profileId',
        'name',
        'status',
        'output',
        'codec',
        'width',
        'height',
        'videoBitRate',
        'audioBitRate',
        'size',
        'startedAt',
        'endedAt',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('videosRenditions', self::ALLOWED_ATTRIBUTES);
    }
}
