<?php

use Appwrite\Client;
use Appwrite\Services\Tables;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setAdmin('') // 
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tables = new Tables($client);

$result = $tables->createRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: []
);