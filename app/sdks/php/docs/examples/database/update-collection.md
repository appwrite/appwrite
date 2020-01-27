<?php

use Appwrite\Client;
use Appwrite\Services\Database;

$client = new Client();

$client
    ->setProject('')
;

$database = new Database($client);

$result = $database->updateCollection('[COLLECTION_ID]', '[NAME]', [], []);