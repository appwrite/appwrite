<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;

$client = new Client();

$client
    ->setProject('5df5acd0d48c2')
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2')
;

$avatars = new Avatars($client);

$result = $avatars->getQR('[TEXT]');