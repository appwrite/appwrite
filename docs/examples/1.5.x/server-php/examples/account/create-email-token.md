<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2'); // Your project ID

$account = new Account($client);

$result = $account->createEmailToken(
    userId: '<USER_ID>',
    email: 'email@example.com',
    phrase: false // optional
);