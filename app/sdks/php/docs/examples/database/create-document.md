<?php

use Appwrite\Client;
use Appwrite\Services\Database;

$client = new Client();

$client
    setProject('')
    setKey('')
;

$database = new Database($client);

$result = $database->createDocument('[COLLECTION_ID]', '{}');