<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentUsageEvent extends Model
{
    public function __construct()
    {
        $this
            ->addRule('subscriptionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Subscription ID.',
                'default' => '',
                'example' => 'sub_abc123',
            ])
            ->addRule('actorType', [
                'type' => self::TYPE_STRING,
                'description' => 'Actor type (user or team).',
                'default' => 'user',
                'example' => 'user',
            ])
            ->addRule('actorId', [
                'type' => self::TYPE_STRING,
                'description' => 'Actor ID.',
                'default' => '',
                'example' => 'user_123',
            ])
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
                'example' => 'api_calls',
            ])
            ->addRule('quantity', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Usage quantity.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('timestamp', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Event timestamp.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('providerSyncState', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider sync state.',
                'default' => 'pending',
                'example' => 'synced',
            ])
            ->addRule('providerEventId', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider event ID.',
                'default' => '',
                'example' => 'evt_123',
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
        return 'PaymentUsageEvent';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_USAGE_EVENT;
    }
}
