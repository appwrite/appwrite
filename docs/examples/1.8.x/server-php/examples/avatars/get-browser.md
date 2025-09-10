<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;
use Appwrite\Enums\Browser;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$avatars = new Avatars($client);

$result = $avatars->getBrowser(
    code: Browser::AVANTBROWSER(),
    width: 0, // optional
    height: 0, // optional
    quality: -1 // optional
);