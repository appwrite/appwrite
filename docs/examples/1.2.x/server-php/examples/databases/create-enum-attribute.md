<?php

use Appwrite\Client;
use Appwrite\Services\Databases;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$databases = new Databases($client);

$result = $databases->createEnumAttribute('[DATABASE_ID]', '[COLLECTION_ID]', '', [], false);