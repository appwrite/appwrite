<?php

use Appwrite\Client;
use Appwrite\Services\Databases;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setAdmin('') // 
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$databases = new Databases($client);

$result = $databases->createDocuments(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documents: []
);