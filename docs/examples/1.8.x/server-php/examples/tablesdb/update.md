<?php

use Appwrite\Client;
use Appwrite\Services\TablesDB;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tablesDB = new TablesDB($client);

$result = $tablesDB->update(
    databaseId: '<DATABASE_ID>',
    name: '<NAME>',
    enabled: false // optional
);