<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = new Client();

$client
;

$account = new Account($client);

$result = $account->createAccount('email@example.com', 'password');