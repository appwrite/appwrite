<?php

include './vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\Storage;

$client = new Client();

$client
    ->setEndpoint($_ENV['APPWRITE_ENDPOINT']) // Your API Endpoint
    ->setProject($_ENV['APPWRITE_PROJECT']) // Your project ID
    ->setKey($_ENV['APPWRITE_SECRET']) // Your secret API key
;

$storage = new Storage($client);

// $result = $storage->getFile($_ENV['APPWRITE_FILEID']);

echo $_ENV['APPWRITE_FUNCTION_ID']."\n";
echo $_ENV['APPWRITE_FUNCTION_NAME']."\n";
echo $_ENV['APPWRITE_FUNCTION_TAG']."\n";
echo $_ENV['APPWRITE_FUNCTION_TRIGGER']."\n";
echo $_ENV['APPWRITE_FUNCTION_RUNTIME_NAME']."\n";
echo $_ENV['APPWRITE_FUNCTION_RUNTIME_VERSION']."\n";
// echo $result['$id'];
echo $_ENV['APPWRITE_FUNCTION_EVENT']."\n";
echo $_ENV['APPWRITE_FUNCTION_EVENT_DATA']."\n";
// Test unknwon UTF-8 chars
echo "\xEA\xE4\n";