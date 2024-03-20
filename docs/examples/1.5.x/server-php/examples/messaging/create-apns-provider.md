<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

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