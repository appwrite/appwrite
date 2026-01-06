<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class PaymentFeatures extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'featureId',
        'name',
        'type',
        'status'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('payments_features', self::ALLOWED_ATTRIBUTES);
    }
}
