<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class File extends Model
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
        return 'File';
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