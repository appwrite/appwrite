<?php

use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Enums\AuthenticatorType;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$users = new Users($client);

$result = $users->deleteMfaAuthenticator(
    userId: '<USER_ID>',
    type: AuthenticatorType::TOTP()
);