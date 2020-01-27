<?php

use Appwrite\Client;
use Appwrite\Services\Projects;

$client = new Client();

$client
    ->setProject('')
;

$projects = new Projects($client);

$result = $projects->createPlatform('[PROJECT_ID]', 'web', '[NAME]');