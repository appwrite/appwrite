<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Provider extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Provider creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Provider update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'The name for the provider instance.',
                'default' => '',
                'example' => 'Mailgun',
            ])
            ->addRule('provider', [
                'type' => self::TYPE_STRING,
                'description' => 'The name of the provider service.',
                'default' => '',
                'example' => 'mailgun',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is provider enabled?',
                'default' => true,
                'example' => true,
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of provider.',
                'default' => '',
                'example' => MESSAGE_TYPE_SMS,
            ])
            ->addRule('credentials', [
                'type' => self::TYPE_JSON,
                'description' => 'Provider credentials.',
                'default' => [],
                'example' => [
                    'key' => '123456789'
                ],
            ])
            ->addRule('options', [
                'type' => self::TYPE_JSON,
                'description' => 'Provider options.',
                'default' => [],
                'required' => false,
                'example' => [
                    'from' => 'sender-email@mydomain'
                ],
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Provider';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROVIDER;
    }
}
