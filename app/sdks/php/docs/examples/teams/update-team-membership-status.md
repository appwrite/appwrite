<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$teams = new Teams($client);

$result = $teams->updateTeamMembershipStatus('[TEAM_ID]', '[INVITE_ID]', '[USER_ID]', '[SECRET]');