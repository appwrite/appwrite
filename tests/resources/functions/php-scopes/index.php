<?php

require 'vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\Users;

return function ($context) {
    $client = new Client();
    $client
        ->setEndpoint(getenv('APPWRITE_FUNCTION_ENDPOINT'))
        ->setProject(getenv('APPWRITE_FUNCTION_PROJECT_ID'))
        ->setKey($context->req->headers['x-appwrite-key']);
    $users = new Users($client);
    return $context->res->json($users->list());
};
