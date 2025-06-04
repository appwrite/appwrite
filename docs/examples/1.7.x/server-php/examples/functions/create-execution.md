<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$functions = new Functions($client);

$result = $functions->createExecution(
    functionId: '<FUNCTION_ID>',
    body: '<BODY>', // optional
    async: false, // optional
    path: '<PATH>', // optional
    method: ExecutionMethod::GET(), // optional
    headers: [], // optional
    scheduledAt: '' // optional
);