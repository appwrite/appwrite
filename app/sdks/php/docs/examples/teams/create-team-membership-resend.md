<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$teams = new Teams($client);

$result = $teams->createTeamMembershipResend('[TEAM_ID]', '[INVITE_ID]', 'https://example.com');