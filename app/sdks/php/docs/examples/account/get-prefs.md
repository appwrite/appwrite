<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$account = new Account($client);

$result = $account->getPrefs();