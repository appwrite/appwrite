<?php

use Appwrite\Client;
use Appwrite\Services\Tables;
use Appwrite\Enums\RelationshipType;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$tables = new Tables($client);

$result = $tables->createRelationshipColumn(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    relatedTableId: '<RELATED_TABLE_ID>',
    type: RelationshipType::ONETOONE(),
    twoWay: false, // optional
    key: '', // optional
    twoWayKey: '', // optional
    onDelete: RelationMutate::CASCADE() // optional
);