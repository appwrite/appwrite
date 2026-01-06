<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

class PaymentSubscriptions extends Base
{
    public const ALLOWED_ATTRIBUTES = [
        'subscriptionId',
        'actorType',
        'actorId',
        'planId',
        'status',
        'cancelAtPeriodEnd'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct()
    {
        parent::__construct('payments_subscriptions', self::ALLOWED_ATTRIBUTES);
    }
}
