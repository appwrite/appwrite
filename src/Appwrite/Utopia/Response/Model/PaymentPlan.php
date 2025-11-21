<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentPlan extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [ 'type' => self::TYPE_STRING, 'description' => 'Internal document ID', 'default' => '', 'example' => '5e5ea5c16897e' ])
            ->addRule('planId', [ 'type' => self::TYPE_STRING, 'description' => 'Public plan ID', 'default' => '', 'example' => 'pro' ])
            ->addRule('name', [ 'type' => self::TYPE_STRING, 'description' => 'Plan name', 'default' => '', 'example' => 'Pro' ])
            ->addRule('description', [ 'type' => self::TYPE_STRING, 'description' => 'Plan description', 'default' => '', 'example' => 'Pro plan' ])
            ->addRule('pricing', [ 'type' => self::TYPE_JSON, 'description' => 'Pricing options', 'default' => [], 'example' => [] ])
            ->addRule('isDefault', [ 'type' => self::TYPE_BOOLEAN, 'description' => 'Default plan', 'default' => false, 'example' => true ])
            ->addRule('isFree', [ 'type' => self::TYPE_BOOLEAN, 'description' => 'Is plan free', 'default' => false, 'example' => false ])
            ->addRule('status', [ 'type' => self::TYPE_STRING, 'description' => 'Plan status', 'default' => 'active', 'example' => 'active' ])
            ->addRule('providers', [ 'type' => self::TYPE_JSON, 'description' => 'Provider mapping', 'default' => [], 'example' => [] ])
            ->addRule('features', [ 'type' => self::TYPE_JSON, 'description' => 'Features summary', 'default' => [], 'example' => [] ]);
    }

    public function getName(): string
    {
        return 'PaymentPlan';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_PLAN;
    }
}
