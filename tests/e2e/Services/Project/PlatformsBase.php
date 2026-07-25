<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait PlatformsBase
{
    use Async;

    // =========================================================================
    // Create Web platform tests
    // =========================================================================

    public function testCreateWebPlatform(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'My Web App',
            'app.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My Web App', $platform['body']['name']);
        $this->assertSame('web', $platform['body']['type']);
        $this->assertSame('app.example.com', $platform['body']['hostname']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platform['body']['$id'], $get['body']['$id']);
        $this->assertSame('My Web App', $get['body']['name']);
        $this->assertSame('web', $get['body']['type']);
        $this->assertSame('app.example.com', $get['body']['hostname']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['platforms']));

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateWebPlatformWithoutAuthentication(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'No Auth Web',
            'noauth.example.com',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateWebPlatformInvalidId(): void
    {
        $platform = $this->createWebPlatform(
            '!invalid-id!',
            'Invalid ID Web',
            'invalid.example.com',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateWebPlatformMissingName(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            null,
            'missing.example.com',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWebPlatformEmptyHostname(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'Empty Hostname',
            '',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWebPlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createWebPlatform(
            $platformId,
            'Web Dup 1',
            'dup1.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createWebPlatform(
            $platformId,
            'Web Dup 2',
            'dup2.example.com',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('platform_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testCreateWebPlatformCustomId(): void
    {
        $customId = 'my-custom-web-platform';

        $platform = $this->createWebPlatform(
            $customId,
            'Custom ID Web',
            'custom.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame($customId, $platform['body']['$id']);

        // Verify via GET
        $get = $this->getPlatform($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deletePlatform($customId);
    }

    // =========================================================================
    // Create Apple platform tests
    // =========================================================================

    public function testCreateApplePlatform(): void
    {
        $platform = $this->createApplePlatform(
            ID::unique(),
            'My Apple App',
            'com.example.myapp',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My Apple App', $platform['body']['name']);
        $this->assertSame('apple', $platform['body']['type']);
        $this->assertSame('com.example.myapp', $platform['body']['bundleIdentifier']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platform['body']['$id'], $get['body']['$id']);
        $this->assertSame('My Apple App', $get['body']['name']);
        $this->assertSame('apple', $get['body']['type']);
        $this->assertSame('com.example.myapp', $get['body']['bundleIdentifier']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['platforms']));

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateApplePlatformWithoutAuthentication(): void
    {
        $response = $this->createApplePlatform(
            ID::unique(),
            'No Auth Apple',
            'com.example.noauth',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateApplePlatformInvalidId(): void
    {
        $platform = $this->createApplePlatform(
            '!invalid-id!',
            'Invalid ID Apple',
            'com.example.invalidid',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateApplePlatformMissingName(): void
    {
        $response = $this->createApplePlatform(
            ID::unique(),
            null,
            'com.example.missingname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateApplePlatformMissingIdentifier(): void
    {
        $response = $this->createApplePlatform(
            ID::unique(),
            'Missing Identifier',
            null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateApplePlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createApplePlatform(
            $platformId,
            'Apple Dup 1',
            'com.example.dup1',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        $duplicate = $this->createApplePlatform(
            $platformId,
            'Apple Dup 2',
            'com.example.dup2',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('platform_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testCreateApplePlatformCustomId(): void
    {
        $customId = 'my-custom-apple-platform';

        $platform = $this->createApplePlatform(
            $customId,
            'Custom ID Apple',
            'com.example.customid',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame($customId, $platform['body']['$id']);

        // Verify via GET
        $get = $this->getPlatform($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deletePlatform($customId);
    }

    // =========================================================================
    // Create Android platform tests
    // =========================================================================

    public function testCreateAndroidPlatform(): void
    {
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'My Android App',
            'com.example.android',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My Android App', $platform['body']['name']);
        $this->assertSame('android', $platform['body']['type']);
        $this->assertSame('com.example.android', $platform['body']['applicationId']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('android', $get['body']['type']);
        $this->assertSame('com.example.android', $get['body']['applicationId']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateAndroidPlatformWithoutAuthentication(): void
    {
        $response = $this->createAndroidPlatform(
            ID::unique(),
            'No Auth Android',
            'com.example.noauth',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateAndroidPlatformInvalidId(): void
    {
        $platform = $this->createAndroidPlatform(
            '!invalid-id!',
            'Invalid ID Android',
            'com.example.invalidid',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateAndroidPlatformMissingName(): void
    {
        $response = $this->createAndroidPlatform(
            ID::unique(),
            null,
            'com.example.missingname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateAndroidPlatformMissingIdentifier(): void
    {
        $response = $this->createAndroidPlatform(
            ID::unique(),
            'Missing Identifier',
            null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateAndroidPlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createAndroidPlatform(
            $platformId,
            'Android Dup 1',
            'com.example.dup1',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        $duplicate = $this->createAndroidPlatform(
            $platformId,
            'Android Dup 2',
            'com.example.dup2',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('platform_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testCreateAndroidPlatformCustomId(): void
    {
        $customId = 'my-custom-android-platform';

        $platform = $this->createAndroidPlatform(
            $customId,
            'Custom ID Android',
            'com.example.customid',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame($customId, $platform['body']['$id']);

        // Verify via GET
        $get = $this->getPlatform($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deletePlatform($customId);
    }

    // =========================================================================
    // Create Windows platform tests
    // =========================================================================

    public function testCreateWindowsPlatform(): void
    {
        $platform = $this->createWindowsPlatform(
            ID::unique(),
            'My Windows App',
            'com.example.windows',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My Windows App', $platform['body']['name']);
        $this->assertSame('windows', $platform['body']['type']);
        $this->assertSame('com.example.windows', $platform['body']['packageIdentifierName']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('windows', $get['body']['type']);
        $this->assertSame('com.example.windows', $get['body']['packageIdentifierName']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateWindowsPlatformWithoutAuthentication(): void
    {
        $response = $this->createWindowsPlatform(
            ID::unique(),
            'No Auth Windows',
            'com.example.noauth',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateWindowsPlatformInvalidId(): void
    {
        $platform = $this->createWindowsPlatform(
            '!invalid-id!',
            'Invalid ID Windows',
            'com.example.invalidid',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateWindowsPlatformMissingName(): void
    {
        $response = $this->createWindowsPlatform(
            ID::unique(),
            null,
            'com.example.missingname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWindowsPlatformMissingIdentifier(): void
    {
        $response = $this->createWindowsPlatform(
            ID::unique(),
            'Missing Identifier',
            null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWindowsPlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createWindowsPlatform(
            $platformId,
            'Windows Dup 1',
            'com.example.dup1',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        $duplicate = $this->createWindowsPlatform(
            $platformId,
            'Windows Dup 2',
            'com.example.dup2',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('platform_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testCreateWindowsPlatformCustomId(): void
    {
        $customId = 'my-custom-windows-platform';

        $platform = $this->createWindowsPlatform(
            $customId,
            'Custom ID Windows',
            'com.example.customid',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame($customId, $platform['body']['$id']);

        // Verify via GET
        $get = $this->getPlatform($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deletePlatform($customId);
    }

    // =========================================================================
    // Create Linux platform tests
    // =========================================================================

    public function testCreateLinuxPlatform(): void
    {
        $platform = $this->createLinuxPlatform(
            ID::unique(),
            'My Linux App',
            'com.example.linux',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My Linux App', $platform['body']['name']);
        $this->assertSame('linux', $platform['body']['type']);
        $this->assertSame('com.example.linux', $platform['body']['packageName']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('linux', $get['body']['type']);
        $this->assertSame('com.example.linux', $get['body']['packageName']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateLinuxPlatformWithoutAuthentication(): void
    {
        $response = $this->createLinuxPlatform(
            ID::unique(),
            'No Auth Linux',
            'com.example.noauth',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateLinuxPlatformInvalidId(): void
    {
        $platform = $this->createLinuxPlatform(
            '!invalid-id!',
            'Invalid ID Linux',
            'com.example.invalidid',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateLinuxPlatformMissingName(): void
    {
        $response = $this->createLinuxPlatform(
            ID::unique(),
            null,
            'com.example.missingname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateLinuxPlatformMissingIdentifier(): void
    {
        $response = $this->createLinuxPlatform(
            ID::unique(),
            'Missing Identifier',
            null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateLinuxPlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createLinuxPlatform(
            $platformId,
            'Linux Dup 1',
            'com.example.dup1',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        $duplicate = $this->createLinuxPlatform(
            $platformId,
            'Linux Dup 2',
            'com.example.dup2',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('platform_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testCreateLinuxPlatformCustomId(): void
    {
        $customId = 'my-custom-linux-platform';

        $platform = $this->createLinuxPlatform(
            $customId,
            'Custom ID Linux',
            'com.example.customid',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame($customId, $platform['body']['$id']);

        // Verify via GET
        $get = $this->getPlatform($customId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($customId, $get['body']['$id']);

        // Cleanup
        $this->deletePlatform($customId);
    }

    // =========================================================================
    // Update Web platform tests
    // =========================================================================

    public function testUpdateWebPlatform(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Original Web', 'original.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateWebPlatform($platformId, 'Updated Web', 'updated.example.com');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($platformId, $updated['body']['$id']);
        $this->assertSame('Updated Web', $updated['body']['name']);
        $this->assertSame('updated.example.com', $updated['body']['hostname']);

        // Verify update persisted via GET
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated Web', $get['body']['name']);
        $this->assertSame('updated.example.com', $get['body']['hostname']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateWebPlatformWithoutAuthentication(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Auth Update Web', 'authupdate.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->updateWebPlatform($platformId, 'Updated', 'updated.example.com', false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateWebPlatformNotFound(): void
    {
        $updated = $this->updateWebPlatform('non-existent-id', 'New Name', 'new.example.com');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('platform_not_found', $updated['body']['type']);
    }

    public function testUpdateWebPlatformMethodUnsupported(): void
    {
        $platform = $this->createAndroidPlatform(ID::unique(), 'Android Platform', 'com.example.app');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateWebPlatform($platformId, 'Updated Name', 'updated.example.com');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // =========================================================================
    // Update Apple platform tests
    // =========================================================================

    public function testUpdateApplePlatform(): void
    {
        $platform = $this->createApplePlatform(ID::unique(), 'Original Apple', 'com.example.original');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateApplePlatform($platformId, 'Updated Apple', 'com.example.updated');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($platformId, $updated['body']['$id']);
        $this->assertSame('Updated Apple', $updated['body']['name']);
        $this->assertSame('com.example.updated', $updated['body']['bundleIdentifier']);

        // Verify update persisted via GET
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated Apple', $get['body']['name']);
        $this->assertSame('com.example.updated', $get['body']['bundleIdentifier']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateApplePlatformWithoutAuthentication(): void
    {
        $platform = $this->createApplePlatform(ID::unique(), 'Auth Update Apple', 'com.example.authupdate');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->updateApplePlatform($platformId, 'Updated', 'com.example.updated', false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateApplePlatformNotFound(): void
    {
        $updated = $this->updateApplePlatform('non-existent-id', 'New Name', 'com.example.new');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('platform_not_found', $updated['body']['type']);
    }

    public function testUpdateApplePlatformMethodUnsupported(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Web Platform', 'web.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateApplePlatform($platformId, 'Updated Name', 'com.example.updated');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateApplePlatformMissingIdentifier(): void
    {
        $platform = $this->createApplePlatform(ID::unique(), 'Missing Id Apple', 'com.example.missingid');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateApplePlatform($platformId, 'Updated Name', null);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // =========================================================================
    // Update Android platform tests
    // =========================================================================

    public function testUpdateAndroidPlatform(): void
    {
        $platform = $this->createAndroidPlatform(ID::unique(), 'Original Android', 'com.example.original');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateAndroidPlatform($platformId, 'Updated Android', 'com.example.updated');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($platformId, $updated['body']['$id']);
        $this->assertSame('Updated Android', $updated['body']['name']);
        $this->assertSame('com.example.updated', $updated['body']['applicationId']);

        // Verify update persisted via GET
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated Android', $get['body']['name']);
        $this->assertSame('com.example.updated', $get['body']['applicationId']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAndroidPlatformWithoutAuthentication(): void
    {
        $platform = $this->createAndroidPlatform(ID::unique(), 'Auth Update Android', 'com.example.authupdate');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->updateAndroidPlatform($platformId, 'Updated', 'com.example.updated', false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAndroidPlatformNotFound(): void
    {
        $updated = $this->updateAndroidPlatform('non-existent-id', 'New Name', 'com.example.new');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('platform_not_found', $updated['body']['type']);
    }

    public function testUpdateAndroidPlatformMethodUnsupported(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Web Platform', 'web.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateAndroidPlatform($platformId, 'Updated Name', 'com.example.updated');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAndroidPlatformMissingIdentifier(): void
    {
        $platform = $this->createAndroidPlatform(ID::unique(), 'Missing Id Android', 'com.example.missingid');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateAndroidPlatform($platformId, 'Updated Name', null);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // =========================================================================
    // Update Windows platform tests
    // =========================================================================

    public function testUpdateWindowsPlatform(): void
    {
        $platform = $this->createWindowsPlatform(ID::unique(), 'Original Windows', 'com.example.original');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateWindowsPlatform($platformId, 'Updated Windows', 'com.example.updated');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($platformId, $updated['body']['$id']);
        $this->assertSame('Updated Windows', $updated['body']['name']);
        $this->assertSame('com.example.updated', $updated['body']['packageIdentifierName']);

        // Verify update persisted via GET
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated Windows', $get['body']['name']);
        $this->assertSame('com.example.updated', $get['body']['packageIdentifierName']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateWindowsPlatformWithoutAuthentication(): void
    {
        $platform = $this->createWindowsPlatform(ID::unique(), 'Auth Update Windows', 'com.example.authupdate');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->updateWindowsPlatform($platformId, 'Updated', 'com.example.updated', false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateWindowsPlatformNotFound(): void
    {
        $updated = $this->updateWindowsPlatform('non-existent-id', 'New Name', 'com.example.new');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('platform_not_found', $updated['body']['type']);
    }

    public function testUpdateWindowsPlatformMethodUnsupported(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Web Platform', 'web.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateWindowsPlatform($platformId, 'Updated Name', 'com.example.updated');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateWindowsPlatformMissingIdentifier(): void
    {
        $platform = $this->createWindowsPlatform(ID::unique(), 'Missing Id Windows', 'com.example.missingid');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateWindowsPlatform($platformId, 'Updated Name', null);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // =========================================================================
    // Update Linux platform tests
    // =========================================================================

    public function testUpdateLinuxPlatform(): void
    {
        $platform = $this->createLinuxPlatform(ID::unique(), 'Original Linux', 'com.example.original');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateLinuxPlatform($platformId, 'Updated Linux', 'com.example.updated');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($platformId, $updated['body']['$id']);
        $this->assertSame('Updated Linux', $updated['body']['name']);
        $this->assertSame('com.example.updated', $updated['body']['packageName']);

        // Verify update persisted via GET
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated Linux', $get['body']['name']);
        $this->assertSame('com.example.updated', $get['body']['packageName']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateLinuxPlatformWithoutAuthentication(): void
    {
        $platform = $this->createLinuxPlatform(ID::unique(), 'Auth Update Linux', 'com.example.authupdate');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->updateLinuxPlatform($platformId, 'Updated', 'com.example.updated', false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateLinuxPlatformNotFound(): void
    {
        $updated = $this->updateLinuxPlatform('non-existent-id', 'New Name', 'com.example.new');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('platform_not_found', $updated['body']['type']);
    }

    public function testUpdateLinuxPlatformMethodUnsupported(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Web Platform', 'web.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateLinuxPlatform($platformId, 'Updated Name', 'com.example.updated');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateLinuxPlatformMissingIdentifier(): void
    {
        $platform = $this->createLinuxPlatform(ID::unique(), 'Missing Id Linux', 'com.example.missingid');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $updated = $this->updateLinuxPlatform($platformId, 'Updated Name', null);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // =========================================================================
    // Get platform tests
    // =========================================================================

    public function testGetWebPlatform(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Get Test Web', 'gettest.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $get = $this->getPlatform($platformId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platformId, $get['body']['$id']);
        $this->assertSame('Get Test Web', $get['body']['name']);
        $this->assertSame('web', $get['body']['type']);
        $this->assertSame('gettest.example.com', $get['body']['hostname']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testGetApplePlatform(): void
    {
        $platform = $this->createApplePlatform(ID::unique(), 'Get Test Apple', 'com.example.gettest');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $get = $this->getPlatform($platformId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platformId, $get['body']['$id']);
        $this->assertSame('Get Test Apple', $get['body']['name']);
        $this->assertSame('apple', $get['body']['type']);
        $this->assertSame('com.example.gettest', $get['body']['bundleIdentifier']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testGetAndroidPlatform(): void
    {
        $platform = $this->createAndroidPlatform(ID::unique(), 'Get Test Android', 'com.example.gettest');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $get = $this->getPlatform($platformId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platformId, $get['body']['$id']);
        $this->assertSame('Get Test Android', $get['body']['name']);
        $this->assertSame('android', $get['body']['type']);
        $this->assertSame('com.example.gettest', $get['body']['applicationId']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testGetWindowsPlatform(): void
    {
        $platform = $this->createWindowsPlatform(ID::unique(), 'Get Test Windows', 'com.example.gettest');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $get = $this->getPlatform($platformId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platformId, $get['body']['$id']);
        $this->assertSame('Get Test Windows', $get['body']['name']);
        $this->assertSame('windows', $get['body']['type']);
        $this->assertSame('com.example.gettest', $get['body']['packageIdentifierName']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testGetLinuxPlatform(): void
    {
        $platform = $this->createLinuxPlatform(ID::unique(), 'Get Test Linux', 'com.example.gettest');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $get = $this->getPlatform($platformId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platformId, $get['body']['$id']);
        $this->assertSame('Get Test Linux', $get['body']['name']);
        $this->assertSame('linux', $get['body']['type']);
        $this->assertSame('com.example.gettest', $get['body']['packageName']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($get['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($get['body']['$updatedAt']));

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testGetPlatformNotFound(): void
    {
        $get = $this->getPlatform('non-existent-id');

        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('platform_not_found', $get['body']['type']);
    }

    public function testGetPlatformWithoutAuthentication(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Auth Get Web', 'authget.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->getPlatform($platformId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // =========================================================================
    // List platforms tests
    // =========================================================================

    public function testListPlatforms(): void
    {
        // Create one of each platform type
        $web = $this->createWebPlatform(ID::unique(), 'List Web', 'listweb.example.com');
        $this->assertSame(201, $web['headers']['status-code']);

        $apple = $this->createApplePlatform(ID::unique(), 'List Apple', 'com.example.listapple');
        $this->assertSame(201, $apple['headers']['status-code']);

        $android = $this->createAndroidPlatform(ID::unique(), 'List Android', 'com.example.listandroid');
        $this->assertSame(201, $android['headers']['status-code']);

        $windows = $this->createWindowsPlatform(ID::unique(), 'List Windows', 'com.example.listwindows');
        $this->assertSame(201, $windows['headers']['status-code']);

        $linux = $this->createLinuxPlatform(ID::unique(), 'List Linux', 'com.example.listlinux');
        $this->assertSame(201, $linux['headers']['status-code']);

        // List all
        $list = $this->listPlatforms(null, true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(5, $list['body']['total']);
        $this->assertGreaterThanOrEqual(5, \count($list['body']['platforms']));
        $this->assertIsArray($list['body']['platforms']);

        // Verify structure of returned platforms
        foreach ($list['body']['platforms'] as $platform) {
            $this->assertArrayHasKey('$id', $platform);
            $this->assertArrayHasKey('$createdAt', $platform);
            $this->assertArrayHasKey('$updatedAt', $platform);
            $this->assertArrayHasKey('name', $platform);
            $this->assertArrayHasKey('type', $platform);
        }

        // Cleanup
        $this->deletePlatform($web['body']['$id']);
        $this->deletePlatform($apple['body']['$id']);
        $this->deletePlatform($android['body']['$id']);
        $this->deletePlatform($windows['body']['$id']);
        $this->deletePlatform($linux['body']['$id']);
    }

    public function testListPlatformsWithLimit(): void
    {
        $platform1 = $this->createWebPlatform(ID::unique(), 'Limit Web 1', 'limit1.example.com');
        $this->assertSame(201, $platform1['headers']['status-code']);

        $platform2 = $this->createAndroidPlatform(ID::unique(), 'Limit Android 2', 'com.example.limit2');
        $this->assertSame(201, $platform2['headers']['status-code']);

        $list = $this->listPlatforms([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertCount(1, $list['body']['platforms']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);

        // Cleanup
        $this->deletePlatform($platform1['body']['$id']);
        $this->deletePlatform($platform2['body']['$id']);
    }

    public function testListPlatformsWithOffset(): void
    {
        $platform1 = $this->createWebPlatform(ID::unique(), 'Offset Web 1', 'offset1.example.com');
        $this->assertSame(201, $platform1['headers']['status-code']);

        $platform2 = $this->createAndroidPlatform(ID::unique(), 'Offset Android 2', 'com.example.offset2');
        $this->assertSame(201, $platform2['headers']['status-code']);

        $listAll = $this->listPlatforms(null, true);
        $this->assertSame(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['platforms']);

        $listOffset = $this->listPlatforms([
            Query::offset(1)->toString(),
        ], true);

        $this->assertSame(200, $listOffset['headers']['status-code']);
        $this->assertCount($totalAll - 1, $listOffset['body']['platforms']);

        // Cleanup
        $this->deletePlatform($platform1['body']['$id']);
        $this->deletePlatform($platform2['body']['$id']);
    }

    public function testListPlatformsWithoutTotal(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'No Total Web', 'nototal.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);

        $list = $this->listPlatforms(null, false);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['platforms']));

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testListPlatformsCursorPagination(): void
    {
        $platform1 = $this->createWebPlatform(ID::unique(), 'Cursor Web 1', 'cursor1.example.com');
        $this->assertSame(201, $platform1['headers']['status-code']);

        $platform2 = $this->createAndroidPlatform(ID::unique(), 'Cursor Android 2', 'com.example.cursor2');
        $this->assertSame(201, $platform2['headers']['status-code']);

        $page1 = $this->listPlatforms([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['platforms']);
        $cursorId = $page1['body']['platforms'][0]['$id'];

        $page2 = $this->listPlatforms([
            Query::limit(1)->toString(),
            Query::cursorAfter(new Document(['$id' => $cursorId]))->toString(),
        ], true);

        $this->assertSame(200, $page2['headers']['status-code']);
        $this->assertCount(1, $page2['body']['platforms']);
        $this->assertNotEquals($cursorId, $page2['body']['platforms'][0]['$id']);

        // Cleanup
        $this->deletePlatform($platform1['body']['$id']);
        $this->deletePlatform($platform2['body']['$id']);
    }

    public function testListPlatformsWithoutAuthentication(): void
    {
        $response = $this->listPlatforms(null, null, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testListPlatformsInvalidCursor(): void
    {
        $list = $this->listPlatforms([
            Query::cursorAfter(new Document(['$id' => 'non-existent-id']))->toString(),
        ], true);

        $this->assertSame(400, $list['headers']['status-code']);
    }

    public function testListPlatformsFilterByType(): void
    {
        $web = $this->createWebPlatform(ID::unique(), 'Filter Web', 'filter.example.com');
        $this->assertSame(201, $web['headers']['status-code']);

        $android = $this->createAndroidPlatform(ID::unique(), 'Filter Android', 'com.example.filter');
        $this->assertSame(201, $android['headers']['status-code']);

        // Filter by web type
        $list = $this->listPlatforms([
            Query::equal('type', ['web'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        foreach ($list['body']['platforms'] as $platform) {
            $this->assertSame('web', $platform['type']);
        }

        // Filter by android type
        $list = $this->listPlatforms([
            Query::equal('type', ['android'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        foreach ($list['body']['platforms'] as $platform) {
            $this->assertSame('android', $platform['type']);
        }

        // Cleanup
        $this->deletePlatform($web['body']['$id']);
        $this->deletePlatform($android['body']['$id']);
    }

    public function testListPlatformsFilterByName(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'UniqueFilterName', 'filtername.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);

        $list = $this->listPlatforms([
            Query::equal('name', ['UniqueFilterName'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertSame('UniqueFilterName', $list['body']['platforms'][0]['name']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testListPlatformsFilterByHostname(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Hostname Filter', 'uniquehostname.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);

        $list = $this->listPlatforms([
            Query::equal('hostname', ['uniquehostname.example.com'])->toString(),
        ], true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertSame('uniquehostname.example.com', $list['body']['platforms'][0]['hostname']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    // =========================================================================
    // Delete platform tests
    // =========================================================================

    public function testDeletePlatform(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Delete Web', 'delete.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Verify it exists
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);

        // Delete
        $delete = $this->deletePlatform($platformId);
        $this->assertSame(204, $delete['headers']['status-code']);
        $this->assertEmpty($delete['body']);

        // Verify it no longer exists
        $get = $this->getPlatform($platformId);
        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('platform_not_found', $get['body']['type']);
    }

    public function testDeletePlatformNotFound(): void
    {
        $delete = $this->deletePlatform('non-existent-id');

        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('platform_not_found', $delete['body']['type']);
    }

    public function testDeletePlatformWithoutAuthentication(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Delete Auth Web', 'deleteauth.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $response = $this->deletePlatform($platformId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Verify it still exists
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testDeletePlatformRemovedFromList(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Delete List Web', 'deletelist.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $listBefore = $this->listPlatforms(null, true);
        $this->assertSame(200, $listBefore['headers']['status-code']);
        $countBefore = $listBefore['body']['total'];

        $delete = $this->deletePlatform($platformId);
        $this->assertSame(204, $delete['headers']['status-code']);

        $listAfter = $this->listPlatforms(null, true);
        $this->assertSame(200, $listAfter['headers']['status-code']);
        $this->assertSame($countBefore - 1, $listAfter['body']['total']);

        $ids = \array_column($listAfter['body']['platforms'], '$id');
        $this->assertNotContains($platformId, $ids);
    }

    public function testDeletePlatformDoubleDelete(): void
    {
        $platform = $this->createWebPlatform(ID::unique(), 'Double Delete Web', 'doubledelete.example.com');
        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $delete = $this->deletePlatform($platformId);
        $this->assertSame(204, $delete['headers']['status-code']);

        $delete = $this->deletePlatform($platformId);
        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('platform_not_found', $delete['body']['type']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function createWebPlatform(string $platformId, ?string $name, ?string $hostname, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($hostname !== null) {
            $params['hostname'] = $hostname;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/platforms/web', $headers, $params);
    }

    protected function createApplePlatform(string $platformId, ?string $name, ?string $bundleIdentifier, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($bundleIdentifier !== null) {
            $params['bundleIdentifier'] = $bundleIdentifier;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/platforms/apple', $headers, $params);
    }

    protected function createAndroidPlatform(string $platformId, ?string $name, ?string $applicationId, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($applicationId !== null) {
            $params['applicationId'] = $applicationId;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/platforms/android', $headers, $params);
    }

    protected function createWindowsPlatform(string $platformId, ?string $name, ?string $packageIdentifierName, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($packageIdentifierName !== null) {
            $params['packageIdentifierName'] = $packageIdentifierName;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/platforms/windows', $headers, $params);
    }

    protected function createLinuxPlatform(string $platformId, ?string $name, ?string $packageName, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($packageName !== null) {
            $params['packageName'] = $packageName;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/platforms/linux', $headers, $params);
    }

    protected function updateWebPlatform(string $platformId, ?string $name = null, ?string $hostname = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($hostname !== null) {
            $params['hostname'] = $hostname;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/platforms/web/' . $platformId, $headers, $params);
    }

    protected function updateApplePlatform(string $platformId, ?string $name = null, ?string $bundleIdentifier = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($bundleIdentifier !== null) {
            $params['bundleIdentifier'] = $bundleIdentifier;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/platforms/apple/' . $platformId, $headers, $params);
    }

    protected function updateAndroidPlatform(string $platformId, ?string $name = null, ?string $applicationId = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($applicationId !== null) {
            $params['applicationId'] = $applicationId;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/platforms/android/' . $platformId, $headers, $params);
    }

    protected function updateWindowsPlatform(string $platformId, ?string $name = null, ?string $packageIdentifierName = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($packageIdentifierName !== null) {
            $params['packageIdentifierName'] = $packageIdentifierName;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/platforms/windows/' . $platformId, $headers, $params);
    }

    protected function updateLinuxPlatform(string $platformId, ?string $name = null, ?string $packageName = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($packageName !== null) {
            $params['packageName'] = $packageName;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/platforms/linux/' . $platformId, $headers, $params);
    }

    protected function getPlatform(string $platformId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/platforms/' . $platformId, $headers);
    }

    /**
     * @param array<string>|null $queries
     */
    protected function listPlatforms(?array $queries, ?bool $total, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/project/platforms', $headers, [
            'queries' => $queries,
            'total' => $total,
        ]);
    }

    protected function deletePlatform(string $platformId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_DELETE, '/project/platforms/' . $platformId, $headers);
    }
}
