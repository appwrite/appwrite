<?php

use Appwrite\Client;
use Appwrite\Services\Projects;

$client = new Client();

$client
    ->setProject('')
;

$projects = new Projects($client);

$result = $projects->getKey('[PROJECT_ID]', '[KEY_ID]');