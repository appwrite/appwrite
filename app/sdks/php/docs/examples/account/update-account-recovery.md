<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = new Client();

$client
;

$account = new Account($client);

$result = $account->updateAccountRecovery('[USER_ID]', '[SECRET]', 'password', 'password');