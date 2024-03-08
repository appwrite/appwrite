<?php

use Appwrite\Client;
use Appwrite\Services\Databases;
use Appwrite\Enums\RelationshipType;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('5df5acd0d48c2') // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

$databases = new Databases($client);

$result = $databases->createRelationshipAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    relatedCollectionId: '<RELATED_COLLECTION_ID>',
    type: RelationshipType::ONETOONE(),
    twoWay: false, // optional
    key: '', // optional
    twoWayKey: '', // optional
    onDelete: RelationMutate::CASCADE() // optional
);