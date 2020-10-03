<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Log extends Model
{
    public function __construct()
    {
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
        return Response::MODEL_LOCALE;
    }
}