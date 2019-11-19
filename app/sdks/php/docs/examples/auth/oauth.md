<?php

use Appwrite\Client;
use Appwrite\Services\Auth;

$client = new Client();

$client
    ->setProject('')
    ->setKey('')
;

$auth = new Auth($client);

$result = $auth->oauth('bitbucket', 'https://example.com', 'https://example.com');