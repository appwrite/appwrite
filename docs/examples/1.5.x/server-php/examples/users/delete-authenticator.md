<?php

use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Enums\AuthenticatorType;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

$users = new Users($client);

$result = $users->deleteAuthenticator(
    userId: '<USER_ID>',
    type: AuthenticatorType::TOTP()
);