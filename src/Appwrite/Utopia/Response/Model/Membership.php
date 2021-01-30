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
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Team ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'User name.',
                'default' => '',
                'example' => 'VIP',
            ])
            ->addRule('email', [
                'type' => self::TYPE_STRING,
                'description' => 'User email address.',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('invited', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Date, the user has been invited to join the team in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
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
        return 'Membership';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_MEMBERSHIP;
    }
}