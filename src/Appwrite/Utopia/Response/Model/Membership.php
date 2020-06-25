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
                'type' => 'string',
                'description' => 'Membership ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('userId', [
                'type' => 'string',
                'description' => 'User ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('teamId', [
                'type' => 'string',
                'description' => 'Team ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'User name.',
                'default' => '',
                'example' => 'VIP',
            ])
            ->addRule('email', [
                'type' => 'string',
                'description' => 'User email address.',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('invited', [
                'type' => 'integer',
                'description' => 'Date, the user has been invited to join the team in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('joined', [
                'type' => 'integer',
                'description' => 'Date, the user has accepted the invitation to join the team in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('confirm', [
                'type' => 'boolean',
                'description' => 'User confirmation status, true if the user has joined the team or false otherwise.',
                'example' => false,
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