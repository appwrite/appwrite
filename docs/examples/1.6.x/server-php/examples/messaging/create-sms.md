<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->createSms(
    messageId: '<MESSAGE_ID>',
    content: '<CONTENT>',
    topics: [], // optional
    users: [], // optional
    targets: [], // optional
    draft: false, // optional
    scheduledAt: '' // optional
);