<?php

use Appwrite\Client;
use Appwrite\Services\Locale;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setSession(''); // The user session to authenticate with

$locale = new Locale($client);

$result = $locale->listCodes();
