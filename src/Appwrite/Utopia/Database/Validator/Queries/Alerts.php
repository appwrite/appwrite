<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Alerts extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'read',
        'type',
        'channel',
        'messageId',
        'projectId',
        'resourceType',
        'resourceId',
        'parentResourceType',
        'parentResourceId',
        'firstSeen',
        'lastSeen',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('alerts', self::ALLOWED_ATTRIBUTES);
    }
}
