<?php

use Appwrite\Client;
use Appwrite\Services\Storage;

$client = new Client();

$client
    ->setProject('')
    ->setKey('')
;

$storage = new Storage($client);

$result = $storage->listFiles();