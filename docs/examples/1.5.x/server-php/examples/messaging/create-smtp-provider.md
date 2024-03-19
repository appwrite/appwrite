<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

$messaging = new Messaging($client);

$result = $messaging->createSmtpProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    host: '<HOST>',
    port: 1, // optional
    username: '<USERNAME>', // optional
    password: '<PASSWORD>', // optional
    encryption: SmtpEncryption::NONE(), // optional
    autoTLS: false, // optional
    mailer: '<MAILER>', // optional
    fromName: '<FROM_NAME>', // optional
    fromEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    replyToEmail: 'email@example.com', // optional
    enabled: false // optional
);