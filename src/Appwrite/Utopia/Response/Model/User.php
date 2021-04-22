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
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'User name.',
                'default' => '',
                'example' => 'John Doe',
            ])
            ->addRule('registration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'User registration date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('active', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'User status. true is for enabled and false is blocked.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('email', [
                'type' => self::TYPE_STRING,
                'description' => 'User email address.',
                'default' => '',
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