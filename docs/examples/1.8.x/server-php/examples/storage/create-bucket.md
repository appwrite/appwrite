<?php

use Appwrite\Client;
use Appwrite\Services\Storage;
use Appwrite\Enums\Compression;
use Appwrite\Permission;
use Appwrite\Role;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$storage = new Storage($client);

$result = $storage->createBucket(
    bucketId: '<BUCKET_ID>',
    name: '<NAME>',
    permissions: [Permission::read(Role::any())], // optional
    fileSecurity: false, // optional
    enabled: false, // optional
    maximumFileSize: 1, // optional
    allowedFileExtensions: [], // optional
    compression: Compression::NONE(), // optional
    encryption: false, // optional
    antivirus: false, // optional
    transformations: false // optional
);