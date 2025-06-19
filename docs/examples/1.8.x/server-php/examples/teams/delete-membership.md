<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$teams = new Teams($client);

$result = $teams->deleteMembership(
    teamId: '<TEAM_ID>',
    membershipId: '<MEMBERSHIP_ID>'
);