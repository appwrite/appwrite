<?php

use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Enums\PasswordHash;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$users = new Users($client);

$result = $users->createSHAUser(
    userId: '<USER_ID>',
    email: 'email@example.com',
    password: 'password',
    passwordVersion: PasswordHash::SHA1(), // optional
    name: '<NAME>' // optional
);