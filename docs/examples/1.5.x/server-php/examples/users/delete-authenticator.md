<?php

use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Enums\AuthenticatorProvider;

$client = new Client();

$client
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setSession('') // The user session to authenticate with
;

$users = new Users($client);

$result = $users->deleteAuthenticator('[USER_ID]', AuthenticatorProvider::TOTP(), '[OTP]');