<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = new Client();

$client
    ->setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;

$teams = new Teams($client);

$result = $teams->updateMembershipStatus('[TEAM_ID]', '[INVITE_ID]', '[USER_ID]', '[SECRET]');