<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Files extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'signature',
        'mimeType',
        'sizeOriginal',
        'chunksTotal',
        'chunksUploaded',
    ];

    /**
     * Expression constructor
     */
    public function __construct()
    {
        parent::__construct('files', self::ALLOWED_ATTRIBUTES);
    }
}
