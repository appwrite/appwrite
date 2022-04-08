<?php

namespace Appwrite\GraphQL;

use Appwrite\Extend\Exception;
use GraphQL\Error\ClientAware;

class GQLException extends Exception implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }

    public function getCategory(): string
    {
        return 'appwrite';
    }
}