<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class PaymentPlans extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'planId',
        'name',
        'status',
        'isDefault',
        'isFree'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('payments_plans', self::ALLOWED_ATTRIBUTES);
    }
}
