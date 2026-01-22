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
     * @var array<string>
     */
    protected array $allowed = self::ALLOWED_ATTRIBUTES;

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('projects', $this->allowed);
    }

    public function isSelectQueryAllowed(): bool
    {
        return true;
    }

    public function getAllowedAttributes(): array
    {
        return $this->allowed;
    }
}
