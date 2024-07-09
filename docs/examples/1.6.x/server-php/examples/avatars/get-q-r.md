<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setSession(''); // The user session to authenticate with

$avatars = new Avatars($client);

$result = $avatars->getQR(
    text: '<TEXT>',
    size: 1, // optional
    margin: 0, // optional
    download: false // optional
);