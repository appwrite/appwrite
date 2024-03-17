<?php

use Appwrite\Client;
use Appwrite\InputFile;
use Appwrite\Services\Storage;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setSession(''); // The user session to authenticate with

$storage = new Storage($client);

$result = $storage->createFile(
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    file: InputFile::withPath('file.png'),
    permissions: ["read("any")"] // optional
);