<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Projects extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'name',
        'teamId',
        'labels',
        'search',
    ];

    /**
     * @param array|null $allowedAttributes
     * @throws \Exception
     */
    public function __construct(array $allowedAttributes = null)
    {
        parent::__construct('projects', $allowedAttributes ?? static::ALLOWED_ATTRIBUTES);
    }

    public function isSelectQueryAllowed(): bool
    {
        return true;
    }
}
