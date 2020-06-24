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
                'type' => 'string',
                'description' => 'User ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'User name.',
                'default' => '',
                'example' => 'John Doe',
            ])
            ->addRule('registration', [
                'type' => 'integer',
                'description' => 'User registration date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('status', [
                'type' => 'integer',
                'description' => 'User status. 0 for Unavtivated, 1 for active and 2 is blocked.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('email', [
                'type' => 'string',
                'description' => 'User email address.',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('emailVerification', [
                'type' => 'boolean',
                'description' => 'Email verification status.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('prefs', [
                'type' => 'json',
                'description' => 'User preferences as a key-value object',
                'default' => new \stdClass,
                'example' => ['theme' => 'dark', 'timezone' => 'UTC'],
            ])
            ->addRule('roles', [
                'type' => 'string',
                'description' => 'User list of roles',
                'default' => [],
                'example' => [],
                'array' => true,
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