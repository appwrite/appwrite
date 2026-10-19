<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class VideoProfiles extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'search',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('videos_profiles', self::ALLOWED_ATTRIBUTES);
    }
}
