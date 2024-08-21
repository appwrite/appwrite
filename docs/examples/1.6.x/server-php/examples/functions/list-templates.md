<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

$functions = new Functions($client);

$result = $functions->listTemplates(
    runtimes: [], // optional
    useCases: [], // optional
    limit: 1, // optional
    offset: 0 // optional
);