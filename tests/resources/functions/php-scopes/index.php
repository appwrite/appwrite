<?php

require 'vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\Users;

return function ($context) {
    $client = new Client();
    $client
        ->setEndpoint(getenv('APPWRITE_FUNCTION_API_ENDPOINT'))
        ->setProject(getenv('APPWRITE_FUNCTION_PROJECT_ID'))
        ->setKey($context->req->headers['x-appwrite-key']);
    $users = new Users($client);
    $response = $users->list();
    $context->log($response);
    return $context->res->json($response);
};
