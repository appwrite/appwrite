<?php

use Appwrite\Client;
use Appwrite\Services\Account;
use Appwrite\Enums\OAuthProvider;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>'); // Your project ID

$account = new Account($client);

$result = $account->createOAuth2Token(
    provider: OAuthProvider::AMAZON(),
    success: 'https://example.com', // optional
    failure: 'https://example.com', // optional
    scopes: [] // optional
);