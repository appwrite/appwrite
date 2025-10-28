<?php

use Appwrite\Client;
use Appwrite\Services\Functions;
use Appwrite\Enums\;

$client = (new Client())
    ->setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$functions = new Functions($client);

$result = $functions->create(
    functionId: '<FUNCTION_ID>',
    name: '<NAME>',
    runtime: ::NODE145(),
    execute: ["any"], // optional
    events: [], // optional
    schedule: '', // optional
    timeout: 1, // optional
    enabled: false, // optional
    logging: false, // optional
    entrypoint: '<ENTRYPOINT>', // optional
    commands: '<COMMANDS>', // optional
    scopes: [], // optional
    installationId: '<INSTALLATION_ID>', // optional
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>', // optional
    providerBranch: '<PROVIDER_BRANCH>', // optional
    providerSilentMode: false, // optional
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>', // optional
    templateRepository: '<TEMPLATE_REPOSITORY>', // optional
    templateOwner: '<TEMPLATE_OWNER>', // optional
    templateRootDirectory: '<TEMPLATE_ROOT_DIRECTORY>', // optional
    templateVersion: '<TEMPLATE_VERSION>', // optional
    specification: '' // optional
);