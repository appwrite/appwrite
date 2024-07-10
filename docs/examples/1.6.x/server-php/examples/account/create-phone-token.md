<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

$account = new Account($client);

$result = $account->createPhoneToken(
    userId: '<USER_ID>',
    phone: '+12065550100'
);