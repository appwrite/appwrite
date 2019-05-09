<?php

use Appwrite\Client;
use Appwrite\Services\Auth;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$auth = new Auth($client);

$result = $auth->recoveryReset('[USER_ID]', '[TOKEN]', 'password', 'password');