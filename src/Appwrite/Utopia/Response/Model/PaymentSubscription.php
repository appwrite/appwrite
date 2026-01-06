<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentSubscription extends Model
{
    public function __construct()
    {
        $this
            ->addRule('subscriptionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Subscription ID.',
                'default' => '',
                'example' => 'sub_123',
            ])
            ->addRule('actorType', [
                'type' => self::TYPE_STRING,
                'description' => 'Actor type.',
                'default' => '',
                'example' => 'team',
            ])
            ->addRule('actorId', [
                'type' => self::TYPE_STRING,
                'description' => 'Actor ID.',
                'default' => '',
                'example' => 'team_abc',
            ])
            ->addRule('planId', [
                'type' => self::TYPE_STRING,
                'description' => 'Plan ID.',
                'default' => '',
                'example' => 'pro',
            ])
            ->addRule('priceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Selected price ID.',
                'default' => '',
                'example' => 'pro-monthly',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Subscription status.',
                'default' => 'active',
                'example' => 'active',
            ])
            ->addRule('trialEndsAt', [
                'type' => self::TYPE_STRING,
                'description' => 'Trial end date.',
                'default' => '',
                'example' => '2023-12-31T23:59:59.000Z',
            ])
            ->addRule('currentPeriodStart', [
                'type' => self::TYPE_STRING,
                'description' => 'Current billing period start date.',
                'default' => '',
                'example' => '2023-12-01T00:00:00.000Z',
            ])
            ->addRule('currentPeriodEnd', [
                'type' => self::TYPE_STRING,
                'description' => 'Current billing period end date.',
                'default' => '',
                'example' => '2023-12-31T23:59:59.000Z',
            ])
            ->addRule('cancelAtPeriodEnd', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether subscription will cancel at period end.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('canceledAt', [
                'type' => self::TYPE_STRING,
                'description' => 'Cancellation date.',
                'default' => '',
                'example' => '2023-12-31T23:59:59.000Z',
            ])
            ->addRule('checkoutUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Checkout URL for completing subscription payment (only returned on creation).',
                'default' => '',
                'example' => 'https://checkout.stripe.com/c/pay/cs_test_...',
            ])
            ->addRule('plan', [
                'type' => Response::MODEL_PAYMENT_PLAN,
                'description' => 'Embedded plan model.',
                'default' => null,
                'example' => [],
            ])
            ->addRule('features', [
                'type' => Response::MODEL_PAYMENT_FEATURE,
                'description' => 'Feature quotas for the subscribed plan.',
                'default' => [],
                'example' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'PaymentSubscription';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_SUBSCRIPTION;
    }
}
