<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>'); // Your project ID

$account = new Account($client);

$result = $account->createMagicURLToken(
    userId: '<USER_ID>',
    email: 'email@example.com',
    url: 'https://example.com', // optional
    phrase: false // optional
);