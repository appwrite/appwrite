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

    // Create web platform tests

    public function testCreateWebPlatform(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'My Web App',
            'web',
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

    public function testCreateWebPlatformFlutterWeb(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Flutter Web App',
            'flutter-web',
            'flutter.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('flutter-web', $platform['body']['type']);
        $this->assertSame('flutter.example.com', $platform['body']['hostname']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateWebPlatformReactNativeWeb(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'React Native Web App',
            'react-native-web',
            'rn.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('react-native-web', $platform['body']['type']);
        $this->assertSame('rn.example.com', $platform['body']['hostname']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateWebPlatformWithoutAuthentication(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'No Auth Web',
            'web',
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
            'web',
            'invalid.example.com',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateWebPlatformMissingName(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            null,
            'web',
            'missing.example.com',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWebPlatformMissingType(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'Missing Type Web',
            null,
            'missing.example.com',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWebPlatformInvalidType(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'Invalid Type',
            'android',
            'invalid.example.com',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateWebPlatformEmptyHostname(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'Empty Hostname',
            'web',
            '',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    /*
    TODO: Enable in future; Currently Hostname validator seems to allow invalid, possibly for some other flows.
    public function testCreateWebPlatformInvalidHostname(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'Empty Hostname',
            'web',
            'notavalid!hostname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }
    */

    public function testCreateWebPlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createWebPlatform(
            $platformId,
            'Web Dup 1',
            'web',
            'dup1.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createWebPlatform(
            $platformId,
            'Web Dup 2',
            'web',
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
            'web',
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

    // Create Apple platform tests

    public function testCreateApplePlatform(): void
    {
        $platform = $this->createApplePlatform(
            ID::unique(),
            'My iOS App',
            'apple-ios',
            'com.example.myapp',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My iOS App', $platform['body']['name']);
        $this->assertSame('apple-ios', $platform['body']['type']);
        $this->assertSame('com.example.myapp', $platform['body']['bundleIdentifier']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platform['body']['$id'], $get['body']['$id']);
        $this->assertSame('My iOS App', $get['body']['name']);
        $this->assertSame('apple-ios', $get['body']['type']);
        $this->assertSame('com.example.myapp', $get['body']['bundleIdentifier']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['platforms']));

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateApplePlatformMacOS(): void
    {
        $platform = $this->createApplePlatform(
            ID::unique(),
            'My macOS App',
            'apple-macos',
            'com.example.macosapp',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('apple-macos', $platform['body']['type']);
        $this->assertSame('com.example.macosapp', $platform['body']['bundleIdentifier']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    // Create Android platform tests

    public function testCreateAndroidPlatform(): void
    {
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'My Android App',
            'com.example.android',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('android', $platform['body']['type']);
        $this->assertSame('com.example.android', $platform['body']['applicationId']);

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('android', $get['body']['type']);
        $this->assertSame('com.example.android', $get['body']['applicationId']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    // Create Windows platform tests

    public function testCreateWindowsPlatform(): void
    {
        $platform = $this->createWindowsPlatform(
            ID::unique(),
            'My Windows App',
            'com.example.windows',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('windows', $platform['body']['type']);
        $this->assertSame('com.example.windows', $platform['body']['packageIdentifierName']);

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('windows', $get['body']['type']);
        $this->assertSame('com.example.windows', $get['body']['packageIdentifierName']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    // Create Linux platform tests

    public function testCreateLinuxPlatform(): void
    {
        $platform = $this->createLinuxPlatform(
            ID::unique(),
            'My Linux App',
            'linux',
            'com.example.linux',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('linux', $platform['body']['type']);
        $this->assertSame('com.example.linux', $platform['body']['packageName']);

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('linux', $get['body']['type']);
        $this->assertSame('com.example.linux', $get['body']['packageName']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateApplePlatformWithoutAuthentication(): void
    {
        $response = $this->createApplePlatform(
            ID::unique(),
            'No Auth App',
            'apple-ios',
            'com.example.noauth',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateApplePlatformInvalidId(): void
    {
        $platform = $this->createApplePlatform(
            '!invalid-id!',
            'Invalid ID App',
            'apple-ios',
            'com.example.invalidid',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateApplePlatformMissingName(): void
    {
        $response = $this->createApplePlatform(
            ID::unique(),
            null,
            'apple-ios',
            'com.example.missingname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateApplePlatformMissingType(): void
    {
        $response = $this->createApplePlatform(
            ID::unique(),
            'Missing Type',
            null,
            'com.example.missingtype',
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

    public function testCreateApplePlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createApplePlatform(
            $platformId,
            'App Dup 1',
            'apple-ios',
            'com.example.dup1',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createApplePlatform(
            $platformId,
            'App Dup 2',
            'apple-ios',
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
            'Custom ID App',
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

    // Update web platform tests

    public function testUpdateWebPlatform(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Original Web',
            'web',
            'original.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Update name and hostname
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
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Auth Update Web',
            'web',
            'authupdate.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt update without authentication
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
        // Create an Android platform
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'Android Platform',
            'com.example.app',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt to update via web endpoint
        $updated = $this->updateWebPlatform($platformId, 'Updated Name', 'updated.example.com');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // Update Apple platform tests

    public function testUpdateApplePlatform(): void
    {
        $platform = $this->createApplePlatform(
            ID::unique(),
            'Original Apple',
            'apple-ios',
            'com.example.original',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Update name and bundleIdentifier
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

    // Update Android platform tests

    public function testUpdateAndroidPlatform(): void
    {
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'Original Android',
            'com.example.original',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Update name and applicationId
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
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'Auth Update Android',
            'com.example.authupdate',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt update without authentication
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

    public function testUpdateApplePlatformMethodUnsupported(): void
    {
        // Create a web platform
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Web Platform',
            'web',
            'web.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt to update via Apple endpoint
        $updated = $this->updateApplePlatform($platformId, 'Updated Name', 'com.example.updated');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAndroidPlatformMissingIdentifier(): void
    {
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'Missing Id App',
            'com.example.missingid',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Update without applicationId should fail
        $updated = $this->updateAndroidPlatform($platformId, 'Updated Name', null);

        $this->assertSame(400, $updated['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // Get platform tests

    public function testGetWebPlatform(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Get Test Web',
            'web',
            'gettest.example.com',
        );

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

    public function testGetAndroidPlatform(): void
    {
        $platform = $this->createAndroidPlatform(
            ID::unique(),
            'Get Test Android',
            'com.example.gettest',
        );

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

    public function testGetPlatformNotFound(): void
    {
        $get = $this->getPlatform('non-existent-id');

        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('platform_not_found', $get['body']['type']);
    }

    public function testGetPlatformWithoutAuthentication(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Auth Get Web',
            'web',
            'authget.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt GET without authentication
        $response = $this->getPlatform($platformId, false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    // List platforms tests

    public function testListPlatforms(): void
    {
        // Create multiple platforms
        $web = $this->createWebPlatform(
            ID::unique(),
            'List Web',
            'web',
            'listweb.example.com',
        );
        $this->assertSame(201, $web['headers']['status-code']);

        $android = $this->createAndroidPlatform(
            ID::unique(),
            'List Android',
            'com.example.listapp',
        );
        $this->assertSame(201, $android['headers']['status-code']);

        $apple = $this->createApplePlatform(
            ID::unique(),
            'List Apple',
            'apple-ios',
            'com.example.listapple',
        );
        $this->assertSame(201, $apple['headers']['status-code']);

        // List all
        $list = $this->listPlatforms(null, true);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $list['body']['total']);
        $this->assertGreaterThanOrEqual(3, \count($list['body']['platforms']));
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
        $this->deletePlatform($android['body']['$id']);
        $this->deletePlatform($apple['body']['$id']);
    }

    public function testListPlatformsWithLimit(): void
    {
        $platform1 = $this->createWebPlatform(
            ID::unique(),
            'Limit Web 1',
            'web',
            'limit1.example.com',
        );
        $this->assertSame(201, $platform1['headers']['status-code']);

        $platform2 = $this->createAndroidPlatform(
            ID::unique(),
            'Limit App 2',
            'com.example.limit2',
        );
        $this->assertSame(201, $platform2['headers']['status-code']);

        // List with limit of 1
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
        $platform1 = $this->createWebPlatform(
            ID::unique(),
            'Offset Web 1',
            'web',
            'offset1.example.com',
        );
        $this->assertSame(201, $platform1['headers']['status-code']);

        $platform2 = $this->createAndroidPlatform(
            ID::unique(),
            'Offset App 2',
            'com.example.offset2',
        );
        $this->assertSame(201, $platform2['headers']['status-code']);

        // List all to get total
        $listAll = $this->listPlatforms(null, true);
        $this->assertSame(200, $listAll['headers']['status-code']);
        $totalAll = \count($listAll['body']['platforms']);

        // List with offset
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
        $platform = $this->createWebPlatform(
            ID::unique(),
            'No Total Web',
            'web',
            'nototal.example.com',
        );
        $this->assertSame(201, $platform['headers']['status-code']);

        // List with total=false
        $list = $this->listPlatforms(null, false);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['platforms']));

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testListPlatformsCursorPagination(): void
    {
        $platform1 = $this->createWebPlatform(
            ID::unique(),
            'Cursor Web 1',
            'web',
            'cursor1.example.com',
        );
        $this->assertSame(201, $platform1['headers']['status-code']);

        $platform2 = $this->createAndroidPlatform(
            ID::unique(),
            'Cursor App 2',
            'com.example.cursor2',
        );
        $this->assertSame(201, $platform2['headers']['status-code']);

        // Get first page with limit 1
        $page1 = $this->listPlatforms([
            Query::limit(1)->toString(),
        ], true);

        $this->assertSame(200, $page1['headers']['status-code']);
        $this->assertCount(1, $page1['body']['platforms']);
        $cursorId = $page1['body']['platforms'][0]['$id'];

        // Get next page using cursor
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
        $web = $this->createWebPlatform(
            ID::unique(),
            'Filter Web',
            'web',
            'filter.example.com',
        );
        $this->assertSame(201, $web['headers']['status-code']);

        $android = $this->createAndroidPlatform(
            ID::unique(),
            'Filter Android',
            'com.example.filter',
        );
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
        $platform = $this->createWebPlatform(
            ID::unique(),
            'UniqueFilterName',
            'web',
            'filtername.example.com',
        );
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
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Hostname Filter',
            'web',
            'uniquehostname.example.com',
        );
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

    // Delete platform tests

    public function testDeletePlatform(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Delete Web',
            'web',
            'delete.example.com',
        );

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
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Delete Auth Web',
            'web',
            'deleteauth.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt DELETE without authentication
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
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Delete List Web',
            'web',
            'deletelist.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Get list count before delete
        $listBefore = $this->listPlatforms(null, true);
        $this->assertSame(200, $listBefore['headers']['status-code']);
        $countBefore = $listBefore['body']['total'];

        // Delete
        $delete = $this->deletePlatform($platformId);
        $this->assertSame(204, $delete['headers']['status-code']);

        // Get list count after delete
        $listAfter = $this->listPlatforms(null, true);
        $this->assertSame(200, $listAfter['headers']['status-code']);
        $this->assertSame($countBefore - 1, $listAfter['body']['total']);

        // Verify the deleted platform is not in the list
        $ids = \array_column($listAfter['body']['platforms'], '$id');
        $this->assertNotContains($platformId, $ids);
    }

    public function testDeletePlatformDoubleDelete(): void
    {
        $platform = $this->createWebPlatform(
            ID::unique(),
            'Double Delete Web',
            'web',
            'doubledelete.example.com',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // First delete succeeds
        $delete = $this->deletePlatform($platformId);
        $this->assertSame(204, $delete['headers']['status-code']);

        // Second delete returns 404
        $delete = $this->deletePlatform($platformId);
        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('platform_not_found', $delete['body']['type']);
    }

    // Helpers

    protected function createWebPlatform(string $platformId, ?string $name, ?string $type, ?string $hostname, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($type !== null) {
            $params['type'] = $type;
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

    protected function createApplePlatform(string $platformId, ?string $name, ?string $type, ?string $bundleIdentifier, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($type !== null) {
            $params['type'] = $type;
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

    protected function createLinuxPlatform(string $platformId, ?string $name, ?string $type, ?string $packageName, bool $authenticated = true): mixed
    {
        $params = [
            'platformId' => $platformId,
        ];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($type !== null) {
            $params['type'] = $type;
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
