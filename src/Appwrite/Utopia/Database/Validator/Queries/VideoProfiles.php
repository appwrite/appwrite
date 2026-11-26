<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class VideoProfiles extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'codec',
        'width',
        'height',
        'videoBitRate',
        'audioBitRate',
        'search',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('videosProfiles', self::ALLOWED_ATTRIBUTES);
    }
}
