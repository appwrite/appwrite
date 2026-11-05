<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Client extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Client ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Client creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Client update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Client expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('activeSessionId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the session that is currently active on the client. Empty when no session is active, for example right after the active session was deleted.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
            ->addRule('sessions', [
                'type' => Response::MODEL_SESSION,
                'description' => 'List of sessions the client holds. Switch between them to change the active account.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret used to authenticate the client. Only included on creation, and only when the request was made without a cookie.',
                'default' => '',
                'example' => '5e5bb8c16897e',
                'sensitive' => true,
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
        return 'Client';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_CLIENT;
    }
}
