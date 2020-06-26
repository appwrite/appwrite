<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Session extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Session ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('expire', [
                'type' => 'string',
                'description' => 'Session expiration date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('ip', [
                'type' => 'string',
                'description' => 'IP session in use when the session was created.',
                'example' => '127.0.0.1',
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
        return 'Session';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_SESSION;
    }
}