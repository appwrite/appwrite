<?php

use Appwrite\Client;
use Appwrite\Services\Projects;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$projects = new Projects($client);

$result = $projects->getTask('[PROJECT_ID]', '[TASK_ID]');