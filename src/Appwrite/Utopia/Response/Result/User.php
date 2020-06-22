<?php

namespace Appwrite\Utopia\Response\Result;

use Appwrite\Database\Database;
use Appwrite\Utopia\Response\Result;

class User extends Result
{
    public function __construct()
    {
        $this
            ->addRule('$id', 'string', 'User ID.', '5e5ea5c16897e')
            ->addRule('name', 'string', 'User name.', 'John Doe')
            ->addRule('email', 'string', 'User email address.', 'john@appwrite.io')
            ->addRule('emailVerification', 'string', 'Email verification status.', true)
            ->addRule('registration', 'integer', 'User registration date in UNIX format.', 1583261121)
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
        return Database::SYSTEM_COLLECTION_USERS;
    }
}