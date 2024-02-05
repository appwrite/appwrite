<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;
use Appwrite\Enums\Browser;

$client = new Client();

$client
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setSession('') // The user session to authenticate with
;

$avatars = new Avatars($client);

$result = $avatars->getBrowser(Browser::AVANT_BROWSER());