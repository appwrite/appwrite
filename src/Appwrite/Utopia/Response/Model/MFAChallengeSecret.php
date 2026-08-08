<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MFAChallengeSecret extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Token ID.',
                'default' => '',
                'example' => 'bb8ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c168bb8',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Challenge secret to be delivered to the end user through a custom channel.',
                'default' => '',
                'example' => '446372',
            ]);
    }

    /**
     * Get Name
     */
    public function getName(): string
    {
        return 'MFA Challenge Secret';
    }

    /**
     * Get Type
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_CHALLENGE_SECRET;
    }
}
