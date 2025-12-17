<?php

use Appwrite\Client;
use Appwrite\Services\TablesDB;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tablesDB = new TablesDB($client);

$result = $tablesDB->updateRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    data: [
        'username' => 'walter.obrien',
        'email' => 'walter.obrien@example.com',
        'fullName' => 'Walter O'Brien',
        'age' => 33,
        'isAdmin' => false
    ], // optional
    queries: [], // optional
    transactionId: '<TRANSACTION_ID>' // optional
);