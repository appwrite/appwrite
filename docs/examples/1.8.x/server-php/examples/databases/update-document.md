<?php

use Appwrite\Client;
use Appwrite\Services\Databases;
use Appwrite\Permission;
use Appwrite\Role;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$databases = new Databases($client);

$result = $databases->updateDocument(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    data: [
        'username' => 'walter.obrien',
        'email' => 'walter.obrien@example.com',
        'fullName' => 'Walter O'Brien',
        'age' => 33,
        'isAdmin' => false
    ], // optional
    permissions: [Permission::read(Role::any())], // optional
    transactionId: '<TRANSACTION_ID>' // optional
);