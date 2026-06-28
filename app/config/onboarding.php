<?php

/**
 * Project onboarding stages keyed by SDK method (namespace + method name, same as Appwrite\SDK\Method).
 * Values are `true` for O(1) isset() lookup in the API shutdown hook. Each key is persisted under
 * project.onboarding when the matching API call succeeds.
 */
return [
    // Connect — register platform
    'project.createWebPlatform' => true,
    'project.createAndroidPlatform' => true,
    'project.createApplePlatform' => true,
    'project.createWindowsPlatform' => true,
    'project.createLinuxPlatform' => true,
    // Connect — API key
    'project.createKey' => true,

    // Auth
    'users.create' => true,
    'account.create' => true,
    'account.createAnonymousSession' => true,
    'teams.create' => true,

    // Databases — tablesDB
    'tablesDB.create' => true,
    'tablesDB.createTable' => true,
    'tablesDB.createRow' => true,
    // Databases — documentsDB
    'documentsDB.create' => true,
    'documentsDB.createCollection' => true,
    'documentsDB.createDocument' => true,
    // Databases — legacy
    'databases.create' => true,
    'databases.createCollection' => true,
    'databases.createDocument' => true,

    // Storage
    'storage.createBucket' => true,
    'storage.createFile' => true,

    // Functions
    'functions.create' => true,
    // One key per deployment creation type (deployment `type` attribute);
    // OR them in the UI for a single "created a deployment" milestone.
    'functions.createManualDeployment' => true,
    'functions.createCliDeployment' => true,
    'functions.createVcsDeployment' => true,
    'functions.updateFunctionDeployment' => true,

    // Messaging
    'messaging.createTopic' => true,
    'messaging.createMailgunProvider' => true,
    'messaging.createSendgridProvider' => true,
    'messaging.createSesProvider' => true,
    'messaging.createResendProvider' => true,
    'messaging.createSmtpProvider' => true,
    'messaging.createSMTPProvider' => true,
    'messaging.createMsg91Provider' => true,
    'messaging.createTelesignProvider' => true,
    'messaging.createTextmagicProvider' => true,
    'messaging.createTwilioProvider' => true,
    'messaging.createVonageProvider' => true,
    'messaging.createFcmProvider' => true,
    'messaging.createFCMProvider' => true,
    'messaging.createApnsProvider' => true,
    'messaging.createAPNSProvider' => true,

    // Sites
    'sites.create' => true,
    // One key per deployment creation type (deployment `type` attribute);
    // OR them in the UI for a single "created a deployment" milestone.
    'sites.createManualDeployment' => true,
    'sites.createCliDeployment' => true,
    'sites.createVcsDeployment' => true,
    'sites.updateSiteDeployment' => true,
];
