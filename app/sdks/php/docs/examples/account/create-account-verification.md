<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = new Client();

$client
    ->setProject('')
;

$account = new Account($client);

$result = $account->createAccountVerification('https://example.com');