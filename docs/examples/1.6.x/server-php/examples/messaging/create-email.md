<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->createEmail(
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [], // optional
    users: [], // optional
    targets: [], // optional
    cc: [], // optional
    bcc: [], // optional
    attachments: [], // optional
    draft: false, // optional
    html: false, // optional
    scheduledAt: '' // optional
);