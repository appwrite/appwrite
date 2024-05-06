<?php

use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Enums\OAuthProvider;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2'); // Your project ID

$account = new Account($client);

$result = $account->createOAuth2Session(
    provider: OAuthProvider::AMAZON(),
    success: 'https://example.com', // optional
    failure: 'https://example.com', // optional
    scopes: [] // optional
);