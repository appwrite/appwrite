<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class ReviewLearnings extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'repositoryId',
        'author',
    ];

    public function __construct()
    {
        parent::__construct('reviewLearnings', self::ALLOWED_ATTRIBUTES);
    }
}
