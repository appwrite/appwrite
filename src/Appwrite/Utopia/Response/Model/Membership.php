<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Membership extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Membership ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Membership creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Membership update date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('userName', [
                'type' => self::TYPE_STRING,
                'description' => 'User name.',
                'default' => '',
                'example' => 'John Doe',
            ])
            ->addRule('userEmail', [
                'type' => self::TYPE_STRING,
                'description' => 'User email address.',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Team ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('teamName', [
                'type' => self::TYPE_STRING,
                'description' => 'Team name.',
                'default' => '',
                'example' => 'VIP',
            ])
            ->addRule('invited', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Date, the user has been invited to join the team in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Token secret key. This will return an empty string unless the response is returned using an API key or as part of a webhook payload.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('joined', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Date, the user has accepted the invitation to join the team in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('confirm', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'User confirmation status, true if the user has joined the team or false otherwise.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('roles', [
                'type' => self::TYPE_STRING,
                'description' => 'User list of roles',
                'default' => [],
                'example' => 'admin',
                'array' => true,
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
        return 'Membership';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MEMBERSHIP;
    }
}
