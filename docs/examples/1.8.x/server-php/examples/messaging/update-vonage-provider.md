<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->updateVonageProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // optional
    enabled: false, // optional
    apiKey: '<API_KEY>', // optional
    apiSecret: '<API_SECRET>', // optional
    from: '<FROM>' // optional
);