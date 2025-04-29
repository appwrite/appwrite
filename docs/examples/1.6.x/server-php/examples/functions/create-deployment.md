<?php

use Appwrite\Client;
use Appwrite\InputFile;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$functions = new Functions($client);

$result = $functions->createDeployment(
    functionId: '<FUNCTION_ID>',
    code: InputFile::withPath('file.png'),
    activate: false,
    entrypoint: '<ENTRYPOINT>', // optional
    commands: '<COMMANDS>' // optional
);