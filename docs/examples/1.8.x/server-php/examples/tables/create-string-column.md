<?php

use Appwrite\Client;
use Appwrite\Services\Tables;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tables = new Tables($client);

$result = $tables->createStringColumn(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    key: '',
    size: 1,
    required: false,
    default: '<DEFAULT>', // optional
    array: false, // optional
    encrypt: false // optional
);