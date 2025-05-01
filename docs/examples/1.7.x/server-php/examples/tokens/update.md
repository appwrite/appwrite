<?php

use Appwrite\Client;
use Appwrite\Services\Tokens;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$tokens = new Tokens($client);

$result = $tokens->update(
    tokenId: '<TOKEN_ID>',
    expire: '', // optional
    permissions: ["read("any")"] // optional
);