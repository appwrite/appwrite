<?php

use Appwrite\Client;
use Appwrite\Services\Health;
use Appwrite\Enums\;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

$health = new Health($client);

$result = $health->getFailedJobs(
    name: ::V1DATABASE(),
    threshold: null // optional
);