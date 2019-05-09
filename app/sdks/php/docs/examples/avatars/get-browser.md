<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$avatars = new Avatars($client);

$result = $avatars->getBrowser('aa');