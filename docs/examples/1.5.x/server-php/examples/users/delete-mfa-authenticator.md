<?php

use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Enums\AuthenticatorType;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$users = new Users($client);

$result = $users->deleteMfaAuthenticator(
    userId: '<USER_ID>',
    type: AuthenticatorType::TOTP()
);