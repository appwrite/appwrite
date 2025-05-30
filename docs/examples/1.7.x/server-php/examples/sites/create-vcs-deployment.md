<?php

use Appwrite\Client;
use Appwrite\Services\Sites;
use Appwrite\Enums\VCSDeploymentType;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$sites = new Sites($client);

$result = $sites->createVcsDeployment(
    siteId: '<SITE_ID>',
    type: VCSDeploymentType::BRANCH(),
    reference: '<REFERENCE>',
    activate: false // optional
);