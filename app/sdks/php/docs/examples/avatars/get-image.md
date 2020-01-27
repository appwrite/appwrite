<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;

$client = new Client();

$client
    ->setProject('')
;

$avatars = new Avatars($client);

$result = $avatars->getImage('https://example.com');