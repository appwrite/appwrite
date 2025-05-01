<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$functions = new Functions($client);

$result = $functions->createTemplateDeployment(
    functionId: '<FUNCTION_ID>',
    repository: '<REPOSITORY>',
    owner: '<OWNER>',
    rootDirectory: '<ROOT_DIRECTORY>',
    version: '<VERSION>',
    activate: false // optional
);