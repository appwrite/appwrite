<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class VideoCaptions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'language',
        'default',
        'status',
        'size',
        'bucketId',
        'fileId',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('videosCaptions', self::ALLOWED_ATTRIBUTES, ['language' => 'code']);
    }
}
