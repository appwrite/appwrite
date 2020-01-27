<?php

use Appwrite\Client;
use Appwrite\Services\Users;

$client = new Client();

$client
    ->setProject('')
;

$users = new Users($client);

$result = $users->deleteUserSession('[USER_ID]', '[SESSION_ID]');