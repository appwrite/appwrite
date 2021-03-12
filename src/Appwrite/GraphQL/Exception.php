<?php

namespace Appwrite\GraphQL;

use GraphQL\Error\ClientAware;

class Exception extends \Exception implements ClientAware
{
    public function isClientSafe()
    {
        return true;
    }

    public function getCategory()
    {
        return 'Appwrite Server Exception';
    }
}