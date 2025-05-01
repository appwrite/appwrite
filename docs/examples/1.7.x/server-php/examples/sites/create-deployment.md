<?php

use Appwrite\Client;
use Appwrite\InputFile;
use Appwrite\Services\Sites;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$sites = new Sites($client);

$result = $sites->createDeployment(
    siteId: '<SITE_ID>',
    code: InputFile::withPath('file.png'),
    activate: false,
    installCommand: '<INSTALL_COMMAND>', // optional
    buildCommand: '<BUILD_COMMAND>', // optional
    outputDirectory: '<OUTPUT_DIRECTORY>' // optional
);