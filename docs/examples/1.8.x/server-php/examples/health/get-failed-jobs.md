<?php

use Appwrite\Client;
use Appwrite\Services\Health;
use Appwrite\Enums\;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$health = new Health($client);

$result = $health->getFailedJobs(
    name: ::V1DATABASE(),
    threshold: null // optional
);