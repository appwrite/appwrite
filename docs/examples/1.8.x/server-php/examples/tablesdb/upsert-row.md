<?php

use Appwrite\Client;
use Appwrite\Services\TablesDb;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$tablesDb = new TablesDb($client);

$result = $tablesDb->upsertRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: [], // optional
    permissions: ["read("any")"] // optional
);