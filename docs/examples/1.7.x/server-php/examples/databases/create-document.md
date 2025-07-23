<?php

use Appwrite\Client;
use Appwrite\Services\Databases;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setAdmin('') // 
    ->setSession('') // The user session to authenticate with
    ->setKey('<YOUR_API_KEY>') // Your secret API key
    ->setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

$databases = new Databases($client);

$result = $databases->createDocument(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documentId: '<DOCUMENT_ID>',
    data: [],
    permissions: ["read("any")"] // optional
);