<?php

use Appwrite\Client;
use Appwrite\Services\Locale;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setSession(''); // The user session to authenticate with

$locale = new Locale($client);

$result = $locale->listContinents();
