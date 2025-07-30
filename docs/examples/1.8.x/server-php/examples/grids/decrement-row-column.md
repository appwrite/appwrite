<?php

use Appwrite\Client;
use Appwrite\Services\Grids;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$grids = new Grids($client);

$result = $grids->decrementRowColumn(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    column: '',
    value: null, // optional
    min: null // optional
);