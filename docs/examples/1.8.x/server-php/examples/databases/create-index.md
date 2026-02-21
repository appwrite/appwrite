```php
<?php

use Appwrite\Client;
use Appwrite\Services\Databases;
use Appwrite\Enums\IndexType;
use Appwrite\Enums\OrderBy;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$databases = new Databases($client);

$result = $databases->createIndex(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    type: IndexType::KEY(),
    attributes: [],
    orders: [OrderBy::ASC()], // optional
    lengths: [] // optional
);```
