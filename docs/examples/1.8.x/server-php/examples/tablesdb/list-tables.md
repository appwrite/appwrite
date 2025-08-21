<?php

use Appwrite\Client;
use Appwrite\Services\TablesDb;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tablesDB = new TablesDb($client);

$result = $tablesDB->listTables(
    databaseId: '<DATABASE_ID>',
    queries: [], // optional
    search: '<SEARCH>' // optional
);