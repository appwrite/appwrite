<?php

use Appwrite\Client;
use Appwrite\Services\Storage;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setSession(''); // The user session to authenticate with

$storage = new Storage($client);

$result = $storage->listFiles(
    bucketId: '<BUCKET_ID>',
    queries: [], // optional
    search: '<SEARCH>' // optional
);