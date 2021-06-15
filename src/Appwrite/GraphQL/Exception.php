<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\ClientAware;

class Exception extends \Exception implements ClientAware
{

    function __construct(string $message = '', int $code = 0, string $version = '') {
        $this->message = $message;
        $this->code = $code;
        $this->version = $version; 
    }
    
    public function isClientSafe()
    {
        return true;
    }

    public function getCategory()
    {
        return 'Appwrite Server Exception';
    }
}