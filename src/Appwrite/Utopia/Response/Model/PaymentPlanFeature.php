<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentPlanFeature extends Model
{
    public function __construct()
    {
        $this
            ->addRule('planId', [
                'type' => self::TYPE_STRING,
                'description' => 'Plan ID.',
                'default' => '',
                'example' => 'pro',
            ])
            ->addRule('featureId', [
                'type' => self::TYPE_STRING,
                'description' => 'Feature ID.',
                'default' => '',
                'example' => 'seats',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Feature type (boolean, metered).',
                'default' => 'boolean',
                'example' => 'metered',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the feature is enabled for this plan.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('currency', [
                'type' => self::TYPE_STRING,
                'description' => 'Currency code for metered features.',
                'default' => '',
                'example' => 'usd',
            ])
            ->addRule('interval', [
                'type' => self::TYPE_STRING,
                'description' => 'Billing interval for metered features.',
                'default' => '',
                'example' => 'month',
            ])
            ->addRule('includedUnits', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of units included in the plan.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('tiersMode', [
                'type' => self::TYPE_STRING,
                'description' => 'Pricing tiers mode (graduated or volume).',
                'default' => '',
                'example' => 'graduated',
            ])
            ->addRule('tiers', [
                'type' => self::TYPE_JSON,
                'description' => 'Pricing tiers configuration.',
                'default' => [],
                'example' => [],
            ])
            ->addRule('usageCap', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum usage cap.',
                'default' => null,
                'example' => 1000,
            ])
            ->addRule('overagePrice', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Price per unit for overage usage.',
                'default' => null,
                'example' => 100,
            ])
            ->addRule('providers', [
                'type' => self::TYPE_JSON,
                'description' => 'Provider-specific metadata.',
                'default' => [],
                'example' => [],
            ])
            ->addRule('metadata', [
                'type' => self::TYPE_JSON,
                'description' => 'Additional metadata.',
                'default' => [],
                'example' => [],
            ]);
    }

    public function getName(): string
    {
        return 'PaymentPlanFeature';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_PLAN_FEATURE;
    }
}
