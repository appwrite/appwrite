<?php

use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Enums\AuthenticationFactor;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2'); // Your project ID

$account = new Account($client);

$result = $account->create2FAChallenge(
    factor: AuthenticationFactor::TOTP()
);