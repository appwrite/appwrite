<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Transaction extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Transaction ID.',
                'default' => '',
                'example' => '259125845563242502',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transaction creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transaction update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Current status of the transaction. One of: pending, committing, committed, rolled_back, failed.',
                'default' => 'pending',
                'example' => 'pending',
            ])
            ->addRule('operations', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of operations in the transaction.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('expiresAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Expiration time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'Transaction';
    }

    public function getType(): string
    {
        return Response::MODEL_TRANSACTION;
    }
}
