<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->createPush(
    messageId: '<MESSAGE_ID>',
    title: '<TITLE>', // optional
    body: '<BODY>', // optional
    topics: [], // optional
    users: [], // optional
    targets: [], // optional
    data: [], // optional
    action: '<ACTION>', // optional
    image: '[ID1:ID2]', // optional
    icon: '<ICON>', // optional
    sound: '<SOUND>', // optional
    color: '<COLOR>', // optional
    tag: '<TAG>', // optional
    badge: null, // optional
    draft: false, // optional
    scheduledAt: '', // optional
    contentAvailable: false, // optional
    critical: false, // optional
    priority: MessagePriority::NORMAL() // optional
);