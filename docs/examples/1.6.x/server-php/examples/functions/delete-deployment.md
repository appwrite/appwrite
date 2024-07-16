<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$functions = new Functions($client);

$result = $functions->deleteDeployment(
    functionId: '<FUNCTION_ID>',
    deploymentId: '<DEPLOYMENT_ID>'
);