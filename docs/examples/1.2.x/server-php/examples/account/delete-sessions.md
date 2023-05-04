<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = new Client();

$client
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;

$account = new Account($client);

$result = $account->deleteSessions();