<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setSession(''); // The user session to authenticate with

$functions = new Functions($client);

$result = $functions->getExecution(
    functionId: '<FUNCTION_ID>',
    executionId: '<EXECUTION_ID>'
);