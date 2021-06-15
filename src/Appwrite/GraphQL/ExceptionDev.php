<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\ClientAware;

class ExceptionDev extends \Exception implements ClientAware
{

    function __construct(string $message = '', int $code = 0, string $version = '', string $file = '', int $line = -1, array $trace = []) {
        $this->message = $message;
        $this->code = $code;
        $this->version = $version; 
        $this->file = $file;
        $this->line = $line;
        $this->trace = $trace;
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