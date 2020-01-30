<?php

use Appwrite\Client;
use Appwrite\Services\Locale;

$client = new Client();

$client
    ->setProject('')
    ->setKey('')
;

$locale = new Locale($client);

$result = $locale->get();