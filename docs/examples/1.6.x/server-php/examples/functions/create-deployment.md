<?php

use Appwrite\Client;
use Appwrite\InputFile;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$functions = new Functions($client);

$result = $functions->createDeployment(
    functionId: '<FUNCTION_ID>',
    code: InputFile::withPath('file.png'),
    activate: false,
    entrypoint: '<ENTRYPOINT>', // optional
    commands: '<COMMANDS>' // optional
);