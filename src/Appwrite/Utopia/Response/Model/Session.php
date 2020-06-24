<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Session extends Model
{
    public function __construct()
    {
        $this->addRule('roles', [
            'type' => 'string',
            'description' => 'User list of roles',
            'default' => [],
            'example' => [],
            'array' => true,
        ]);
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