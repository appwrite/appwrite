<?php

use Appwrite\Client;
use Appwrite\Services\Auth;

$client = new Client();

$client
    ->setProject('')
    ->setKey('')
;

$auth = new Auth($client);

$result = $auth->recovery('email@example.com', 'https://example.com');