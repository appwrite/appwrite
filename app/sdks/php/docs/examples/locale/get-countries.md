<?php

use Appwrite\Client;
use Appwrite\Services\Locale;

$client = new Client();

$client
    ->setProject('')
;

$locale = new Locale($client);

$result = $locale->getCountries();