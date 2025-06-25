<?php

use Appwrite\Client;
use Appwrite\Services\Tables;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setSession('') // The user session to authenticate with
    ->setKey('<YOUR_API_KEY>') // Your secret API key
    ->setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

$tables = new Tables($client);

$result = $tables->upsertRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>'
);