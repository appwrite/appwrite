<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>'); // Your project ID

$account = new Account($client);

$result = $account->createEmailPasswordSession(
    email: 'email@example.com',
    password: 'password'
);