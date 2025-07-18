<?php

use Appwrite\Client;
use Appwrite\Services\Sites;
use Appwrite\Enums\;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$sites = new Sites($client);

$result = $sites->update(
    siteId: '<SITE_ID>',
    name: '<NAME>',
    framework: ::ANALOG(),
    enabled: false, // optional
    logging: false, // optional
    timeout: 1, // optional
    installCommand: '<INSTALL_COMMAND>', // optional
    buildCommand: '<BUILD_COMMAND>', // optional
    outputDirectory: '<OUTPUT_DIRECTORY>', // optional
    buildRuntime: ::NODE145(), // optional
    adapter: ::STATIC(), // optional
    fallbackFile: '<FALLBACK_FILE>', // optional
    installationId: '<INSTALLATION_ID>', // optional
    providerRepositoryId: '<PROVIDER_REPOSITORY_ID>', // optional
    providerBranch: '<PROVIDER_BRANCH>', // optional
    providerSilentMode: false, // optional
    providerRootDirectory: '<PROVIDER_ROOT_DIRECTORY>', // optional
    specification: '' // optional
);