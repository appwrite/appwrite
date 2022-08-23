<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class Files extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',

        'name',
        'signature',
        'mimeType',
        'sizeOriginal',
        'chunksTotal',
        'chunksUploaded'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('files', self::ALLOWED_ATTRIBUTES);
    }
}
