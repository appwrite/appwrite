<?php

use Appwrite\Client;
use Appwrite\Services\Auth;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$auth = new Auth($client);

$result = $auth->oauthCallback('[PROJECT_ID]', 'bitbucket', '[CODE]');