<?php

use Appwrite\Client;
use Appwrite\Services\Locale;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$locale = new Locale($client);

$result = $locale->listContinents();
