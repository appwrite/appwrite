<?php

use Appwrite\Client;
use Appwrite\Services\Teams;

$client = new Client();

$client
    ->setProject('')
;

$teams = new Teams($client);

$result = $teams->deleteTeam('[TEAM_ID]');