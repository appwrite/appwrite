<?php

use Appwrite\Client;
use Appwrite\Services\Storage;

$client = new Client();

$client
    ->setProject('5df5acd0d48c2')
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2')
;

$storage = new Storage($client);

$result = $storage->listFiles();