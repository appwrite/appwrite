<?php

namespace Appwrite\Response\Result;

use Appwrite\Database\Database;
use Appwrite\Response\Result;

class Token extends Result
{
    public function __construct()
    {
        $this
            ->addRule('$id', 'string', 'Token ID.', '5e5ea5c16897e')
            ->addRule('expire', 'integer', 'Token expiration date in UNIX format.', 1583261121)
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
    public function getCollection():string
    {
        return Database::SYSTEM_COLLECTION_TOKENS;
    }
}