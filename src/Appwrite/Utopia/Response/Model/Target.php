<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Target extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Target ID.',
                'default' => '',
                'example' => '259125845563242502',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Target creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Target update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Target Name.',
                'default' => '',
                'example' => 'Aegon apple token',
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '259125845563242502',
            ])
            ->addRule('providerId', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider ID.',
                'required' => false,
                'default' => '',
                'example' => '259125845563242502',
            ])
            ->addRule('providerType', [
                'type' => self::TYPE_STRING,
                'description' => 'The target provider type. Can be one of the following: `email`, `sms` or `push`.',
                'default' => '',
                'example' => MESSAGE_TYPE_EMAIL,
            ])
            ->addRule('identifier', [
                'type' => self::TYPE_STRING,
                'description' => 'The target identifier.',
                'default' => '',
                'example' => 'token',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Target';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TARGET;
    }
}
