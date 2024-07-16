<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    ->setSession(''); // The user session to authenticate with

$teams = new Teams($client);

$result = $teams->listMemberships(
    teamId: '<TEAM_ID>',
    queries: [], // optional
    search: '<SEARCH>' // optional
);