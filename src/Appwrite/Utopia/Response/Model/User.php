<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class User extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'User name.',
                'example' => 'John Doe',
            ])
            ->addRule('registration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'User registration date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('status', [
                'type' => self::TYPE_INTEGER,
                'description' => 'User status. 0 for Unavtivated, 1 for active and 2 is blocked.',
                'example' => 0,
            ])
            ->addRule('email', [
                'type' => self::TYPE_STRING,
                'description' => 'User email address.',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('emailVerification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Email verification status.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('prefs', [
                'type' => self::TYPE_JSON,
                'description' => 'User preferences as a key-value object',
                'default' => new \stdClass,
                'example' => ['theme' => 'pink', 'timezone' => 'UTC'],
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'User';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_USER;
    }
}