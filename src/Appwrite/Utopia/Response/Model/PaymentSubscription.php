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
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Subscription status.',
                'default' => 'active',
                'example' => 'active',
            ])
            ->addRule('providers', [
                'type' => self::TYPE_JSON,
                'description' => 'Provider refs.',
                'default' => new \stdClass(),
                'example' => new \stdClass(),
            ])
            ->addRule('plan', [
                'type' => self::TYPE_JSON,
                'description' => 'Embedded plan model.',
                'default' => new \stdClass(),
                'example' => new \stdClass(),
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


