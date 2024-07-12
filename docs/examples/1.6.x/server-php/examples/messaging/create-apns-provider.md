<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->createApnsProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    authKey: '<AUTH_KEY>', // optional
    authKeyId: '<AUTH_KEY_ID>', // optional
    teamId: '<TEAM_ID>', // optional
    bundleId: '<BUNDLE_ID>', // optional
    sandbox: false, // optional
    enabled: false // optional
);