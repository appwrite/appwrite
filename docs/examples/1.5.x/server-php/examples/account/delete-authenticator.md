<?php

use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Enums\AuthenticatorType;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setSession(''); // The user session to authenticate with

$account = new Account($client);

$result = $account->deleteAuthenticator(
    type: AuthenticatorType::TOTP(),
    otp: '<OTP>'
);