<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$teams = new Teams($client);

$result = $teams->updateMembership('[TEAM_ID]', '[MEMBERSHIP_ID]', []);