<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;

class TeamMemberships extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'userId',
        'teamId',
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
