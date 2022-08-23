<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Collection;

class TeamMemberships extends Collection
{
    public const ALLOWED_ATTRIBUTES = [
        '$id',
        '$createdAt',
        '$updatedAt',

        'userId',
        'userName',
        'userName',
        'userEmail',
        'teamId',
        'teamName',
        'invited',
        'joined',
        'confirm'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('teamMemberships', self::ALLOWED_ATTRIBUTES);
    }
}
