<?php

use Appwrite\Client;
use Appwrite\Services\Projects;

$client = new Client();

$client
    ->setProject('')
    ->setKey('')
;

$projects = new Projects($client);

$result = $projects->updateTask('[PROJECT_ID]', '[TASK_ID]', '[NAME]', 'play', '', 0, 'GET', 'https://example.com');