<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class PaymentUsageEvents extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'subscriptionId',
        'featureId',
        'quantity'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('payments_usage_events', self::ALLOWED_ATTRIBUTES);
    }
}
