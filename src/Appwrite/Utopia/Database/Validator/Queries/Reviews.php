<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Reviews extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'repositoryId',
        'providerPullRequestId',
        'providerPullRequestAuthor',
        'providerCommitHash',
        'trigger',
        'status',
        'verdict',
        'tier',
    ];

    public function __construct()
    {
        parent::__construct('reviews', self::ALLOWED_ATTRIBUTES);
    }
}
