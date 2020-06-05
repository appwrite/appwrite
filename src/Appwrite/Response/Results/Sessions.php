<?php

namespace Appwrite\Response\Results;

use Appwrite\Database\Database;
use Appwrite\Response\Result;

class Sessions extends Result
{
    public function __construct()
    {
        $this
            ->addRule('$id', 'string', 'Session ID.', '5e5ea5c16897e')
            ->addRule('expire', 'integer', 'Session expiration date in UNIX format.', 1583261121)
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
    public function getCollection():string
    {
        return Database::SYSTEM_COLLECTION_TOKENS;
    }
}