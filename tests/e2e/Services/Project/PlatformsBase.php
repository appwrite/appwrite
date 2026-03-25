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

    public function testCreateWebPlatformInvalidHostname(): void
    {
        $response = $this->createWebPlatform(
            ID::unique(),
            'Invalid Hostname',
            'web',
            'not a valid hostname!!!',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

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

    // Create app platform tests

    public function testCreateAppPlatform(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'My iOS App',
            'apple-ios',
            'com.example.myapp',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertNotEmpty($platform['body']['$id']);
        $this->assertSame('My iOS App', $platform['body']['name']);
        $this->assertSame('apple-ios', $platform['body']['type']);
        $this->assertSame('com.example.myapp', $platform['body']['identifier']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$createdAt']));
        $this->assertSame(true, $dateValidator->isValid($platform['body']['$updatedAt']));

        // Verify via GET
        $get = $this->getPlatform($platform['body']['$id']);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platform['body']['$id'], $get['body']['$id']);
        $this->assertSame('My iOS App', $get['body']['name']);
        $this->assertSame('apple-ios', $get['body']['type']);
        $this->assertSame('com.example.myapp', $get['body']['identifier']);

        // Verify via LIST
        $list = $this->listPlatforms(null, true);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($list['body']['platforms']));

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateAppPlatformAndroid(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'My Android App',
            'android',
            'com.example.android',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('android', $platform['body']['type']);
        $this->assertSame('com.example.android', $platform['body']['identifier']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateAppPlatformFlutterIos(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'Flutter iOS App',
            'flutter-ios',
            'com.example.flutterios',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $this->assertSame('flutter-ios', $platform['body']['type']);
        $this->assertSame('com.example.flutterios', $platform['body']['identifier']);

        // Cleanup
        $this->deletePlatform($platform['body']['$id']);
    }

    public function testCreateAppPlatformWithoutAuthentication(): void
    {
        $response = $this->createAppPlatform(
            ID::unique(),
            'No Auth App',
            'android',
            'com.example.noauth',
            false
        );

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testCreateAppPlatformInvalidId(): void
    {
        $platform = $this->createAppPlatform(
            '!invalid-id!',
            'Invalid ID App',
            'android',
            'com.example.invalidid',
        );

        $this->assertSame(400, $platform['headers']['status-code']);
    }

    public function testCreateAppPlatformMissingName(): void
    {
        $response = $this->createAppPlatform(
            ID::unique(),
            null,
            'android',
            'com.example.missingname',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateAppPlatformMissingType(): void
    {
        $response = $this->createAppPlatform(
            ID::unique(),
            'Missing Type',
            null,
            'com.example.missingtype',
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateAppPlatformMissingIdentifier(): void
    {
        $response = $this->createAppPlatform(
            ID::unique(),
            'Missing Identifier',
            'android',
            null,
        );

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testCreateAppPlatformDuplicateId(): void
    {
        $platformId = ID::unique();

        $platform = $this->createAppPlatform(
            $platformId,
            'App Dup 1',
            'android',
            'com.example.dup1',
        );

        $this->assertSame(201, $platform['headers']['status-code']);

        // Attempt to create with same ID
        $duplicate = $this->createAppPlatform(
            $platformId,
            'App Dup 2',
            'android',
            'com.example.dup2',
        );

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('platform_already_exists', $duplicate['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testCreateAppPlatformCustomId(): void
    {
        $customId = 'my-custom-app-platform';

        $platform = $this->createAppPlatform(
            $customId,
            'Custom ID App',
            'android',
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
        // Create an app platform
        $platform = $this->createAppPlatform(
            ID::unique(),
            'App Platform',
            'android',
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

    // Update app platform tests

    public function testUpdateAppPlatform(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'Original App',
            'android',
            'com.example.original',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Update name and identifier
        $updated = $this->updateAppPlatform($platformId, 'Updated App', 'com.example.updated');

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($platformId, $updated['body']['$id']);
        $this->assertSame('Updated App', $updated['body']['name']);
        $this->assertSame('com.example.updated', $updated['body']['identifier']);

        // Verify update persisted via GET
        $get = $this->getPlatform($platformId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Updated App', $get['body']['name']);
        $this->assertSame('com.example.updated', $get['body']['identifier']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAppPlatformWithoutAuthentication(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'Auth Update App',
            'android',
            'com.example.authupdate',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Attempt update without authentication
        $response = $this->updateAppPlatform($platformId, 'Updated', 'com.example.updated', false);

        $this->assertSame(401, $response['headers']['status-code']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAppPlatformNotFound(): void
    {
        $updated = $this->updateAppPlatform('non-existent-id', 'New Name', 'com.example.new');

        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('platform_not_found', $updated['body']['type']);
    }

    public function testUpdateAppPlatformMethodUnsupported(): void
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

        // Attempt to update via app endpoint
        $updated = $this->updateAppPlatform($platformId, 'Updated Name', 'com.example.updated');

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('platform_method_unsupported', $updated['body']['type']);

        // Cleanup
        $this->deletePlatform($platformId);
    }

    public function testUpdateAppPlatformMissingIdentifier(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'Missing Id App',
            'android',
            'com.example.missingid',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        // Update without identifier should fail
        $updated = $this->updateAppPlatform($platformId, 'Updated Name', null);

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

    public function testGetAppPlatform(): void
    {
        $platform = $this->createAppPlatform(
            ID::unique(),
            'Get Test App',
            'android',
            'com.example.gettest',
        );

        $this->assertSame(201, $platform['headers']['status-code']);
        $platformId = $platform['body']['$id'];

        $get = $this->getPlatform($platformId);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($platformId, $get['body']['$id']);
        $this->assertSame('Get Test App', $get['body']['name']);
        $this->assertSame('android', $get['body']['type']);
        $this->assertSame('com.example.gettest', $get['body']['identifier']);

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

        $app = $this->createAppPlatform(
            ID::unique(),
            'List App',
            'android',
            'com.example.listapp',
        );
        $this->assertSame(201, $app['headers']['status-code']);

        $flutter = $this->createAppPlatform(
            ID::unique(),
            'List Flutter',
            'flutter-ios',
            'com.example.listflutter',
        );
        $this->assertSame(201, $flutter['headers']['status-code']);

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
        $this->deletePlatform($app['body']['$id']);
        $this->deletePlatform($flutter['body']['$id']);
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

        $platform2 = $this->createAppPlatform(
            ID::unique(),
            'Limit App 2',
            'android',
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

        $platform2 = $this->createAppPlatform(
            ID::unique(),
            'Offset App 2',
            'android',
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

        $platform2 = $this->createAppPlatform(
            ID::unique(),
            'Cursor App 2',
            'android',
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

        $app = $this->createAppPlatform(
            ID::unique(),
            'Filter App',
            'android',
            'com.example.filter',
        );
        $this->assertSame(201, $app['headers']['status-code']);

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
        $this->deletePlatform($app['body']['$id']);
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

    protected function createAppPlatform(string $platformId, ?string $name, ?string $type, ?string $identifier, bool $authenticated = true): mixed
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

        if ($identifier !== null) {
            $params['identifier'] = $identifier;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_POST, '/project/platforms/app', $headers, $params);
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

    protected function updateAppPlatform(string $platformId, ?string $name = null, ?string $identifier = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($name !== null) {
            $params['name'] = $name;
        }

        if ($identifier !== null) {
            $params['identifier'] = $identifier;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PUT, '/project/platforms/app/' . $platformId, $headers, $params);
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
