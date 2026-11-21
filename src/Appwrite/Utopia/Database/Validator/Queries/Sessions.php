<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class Sessions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'ip',
        'countryCode',
        'continentCode',
        'provider',
        'userAgent',
        'osName',
        'osCode',
        'osVersion',
        'clientType',
        'clientName',
        'clientCode',
        'deviceName',
        'deviceBrand',
        'deviceModel',
        'deviceId',
        'expire',
        'factors',
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('sessions', self::ALLOWED_ATTRIBUTES);
    }
}
