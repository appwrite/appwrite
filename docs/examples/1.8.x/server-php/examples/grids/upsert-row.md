<?php

use Appwrite\Client;
use Appwrite\Services\Grids;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$grids = new Grids($client);

$result = $grids->upsertRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: [], // optional
    permissions: ["read("any")"] // optional
);