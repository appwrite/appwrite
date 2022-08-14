<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Token extends Model
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
                'description' => 'Token creation date in Datetime',
                'default' => '',
                'example' => '1975-12-06 13:30:59',
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c168bb8',
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Token secret key. This will return an empty string unless the response is returned using an API key or as part of a webhook payload.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Token expiration date in Unix timestamp.',
                'default' => '',
                'example' => '1975-12-06 13:30:59',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Token';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TOKEN;
    }
}
