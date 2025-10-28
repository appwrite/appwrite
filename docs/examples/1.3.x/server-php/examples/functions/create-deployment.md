<?php

use Appwrite\Client;
use Appwrite\InputFile;
use Appwrite\Services\Functions;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$functions = new Functions($client);

$result = $functions->createDeployment('[FUNCTION_ID]', '[ENTRYPOINT]', InputFile::withPath('file.png'), false);