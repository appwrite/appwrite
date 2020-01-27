<?php

use Appwrite\Client;
use Appwrite\Services\Account;

$client = new Client();

$client
;

$account = new Account($client);

$result = $account->createAccountSessionOAuth('bitbucket', 'https://example.com', 'https://example.com');