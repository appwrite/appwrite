<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class ReviewRepositories extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'installationId',
        'providerRepositoryId',
    ];

    public function __construct()
    {
        parent::__construct('repositories', self::ALLOWED_ATTRIBUTES);
    }
}
