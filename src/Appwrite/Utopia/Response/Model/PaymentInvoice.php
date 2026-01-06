<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PaymentInvoice extends Model
{
    public function __construct()
    {
        $this
            ->addRule('invoiceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Invoice ID.',
                'default' => '',
                'example' => 'inv_1234567890',
            ])
            ->addRule('subscriptionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Subscription ID.',
                'default' => '',
                'example' => 'sub_1234567890',
            ])
            ->addRule('amount', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Invoice amount in cents.',
                'default' => 0,
                'example' => 2999,
            ])
            ->addRule('currency', [
                'type' => self::TYPE_STRING,
                'description' => 'Currency code.',
                'default' => '',
                'example' => 'usd',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Invoice status.',
                'default' => '',
                'example' => 'paid',
            ])
            ->addRule('createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Invoice creation date.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('paidAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Invoice payment date.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('invoiceUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'URL to view the invoice.',
                'default' => '',
                'example' => 'https://invoice.stripe.com/i/acct_123/inv_456',
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
        return 'PaymentInvoice';
    }

    public function getType(): string
    {
        return Response::MODEL_PAYMENT_INVOICE;
    }
}
