<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class PaymentPlanFeatures extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'planId',
        'featureId',
        'enabled',
        'type'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('payments_plan_features', self::ALLOWED_ATTRIBUTES);
    }
}
