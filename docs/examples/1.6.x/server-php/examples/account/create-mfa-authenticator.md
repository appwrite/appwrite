<?php

use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Enums\AuthenticatorType;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setSession(''); // The user session to authenticate with

$account = new Account($client);

$result = $account->createMfaAuthenticator(
    type: AuthenticatorType::TOTP()
);