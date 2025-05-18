<?php

use Appwrite\Client;
use Appwrite\Services\Graphql;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$graphql = new Graphql($client);

$result = $graphql->mutation(
    query: []
);