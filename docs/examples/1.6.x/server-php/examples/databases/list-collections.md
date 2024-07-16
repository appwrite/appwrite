<?php

use Appwrite\Client;
use Appwrite\Services\Databases;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$databases = new Databases($client);

$result = $databases->listCollections(
    databaseId: '<DATABASE_ID>',
    queries: [], // optional
    search: '<SEARCH>' // optional
);