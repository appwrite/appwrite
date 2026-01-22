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
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('projects', $this->getAllowedAttributes());
    }

    public function isSelectQueryAllowed(): bool
    {
        return true;
    }

    public function getAllowedAttributes(): array
    {
        return self::ALLOWED_ATTRIBUTES;
    }
}
