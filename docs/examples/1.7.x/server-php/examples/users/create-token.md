<?php

use Appwrite\Client;
use Appwrite\Services\Users;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$users = new Users($client);

$result = $users->createToken(
    userId: '<USER_ID>',
    length: 4, // optional
    expire: 60 // optional
);