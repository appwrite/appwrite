<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

$functions = new Functions($client);

$result = $functions->getTemplate(
    templateId: '<TEMPLATE_ID>'
);