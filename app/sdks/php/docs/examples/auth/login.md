<?php

use Appwrite\Client;
use Appwrite\Services\Auth;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$auth = new Auth($client);

$result = $auth->login('email@example.com', 'password', 'https://example.com', 'https://example.com');