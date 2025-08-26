<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->updateSMS(
    messageId: '<MESSAGE_ID>',
    topics: [], // optional
    users: [], // optional
    targets: [], // optional
    content: '<CONTENT>', // optional
    draft: false, // optional
    scheduledAt: '' // optional
);