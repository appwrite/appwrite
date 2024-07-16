<?php

use Appwrite\Client;
use Appwrite\Services\Users;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$users = new Users($client);

$result = $users->createSHAUser(
    userId: '<USER_ID>',
    email: 'email@example.com',
    password: 'password',
    passwordVersion: PasswordHash::SHA1(), // optional
    name: '<NAME>' // optional
);