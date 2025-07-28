<?php

use Appwrite\Client;
use Appwrite\Services\Tables;
use Appwrite\Enums\IndexType;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tables = new Tables($client);

$result = $tables->createIndex(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    key: '',
    type: IndexType::KEY(),
    columns: [],
    orders: [], // optional
    lengths: [] // optional
);