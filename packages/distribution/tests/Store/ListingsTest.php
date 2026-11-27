<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\Listing;
use Utopia\Distribution\Store;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

final class ListingsTest extends TestCase
{
    private const string APPLE_KEY_ID = 'ABC123DEFG';

    private const string APPLE_ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string APPLE_ENDPOINT = 'https://store.test/apple';

    private const int APPLE_NOW = 1_795_000_000;

    private const string APPLE_APP_ID = '6450000001';

    private const string APPLE_VERSION_ID = 'b7c1d2e3-1111-4111-8111-000000000001';

    private const string APPLE_LOCALIZATION_ID = 'f1a5b6c7-5555-4555-8555-000000000001';

    private const string APPLE_APP_INFO_ID = 'e2a6b7c8-6666-4666-8666-000000000002';

    private const string APPLE_APP_INFO_LOCALIZATION_ID = 'e2a6b7c8-6666-4666-8666-000000000011';

    private const string APPLE_LIBRARY_ID = '1a2b3c4d-8888-4888-8888-000000000001';

    private const string APPLE_FIRST_IMAGE_ID = '2b3c4d5e-9999-4999-8999-000000000001';

    private const string APPLE_SECOND_IMAGE_ID = '2b3c4d5e-9999-4999-8999-000000000002';

    private const string APPLE_LOST_IMAGE_ID = '2b3c4d5e-9999-4999-8999-000000000009';

    private const string APPLE_STALE_PLACEMENT_ID = '3c4d5e6f-aaaa-4aaa-8aaa-000000000001';

    private const string APPLE_FIRST_PLACEMENT_ID = '3c4d5e6f-aaaa-4aaa-8aaa-000000000002';

    private const string APPLE_SECOND_PLACEMENT_ID = '3c4d5e6f-aaaa-4aaa-8aaa-000000000003';

    private const string APPLE_GROUP = 'IPHONE_DYNAMIC_ISLAND_LARGE_PROFILE';

    private const string APPLE_FIRST_SCREENSHOT = 'iphone-one!!';

    private const string APPLE_SECOND_SCREENSHOT = 'iphone-2!';

    private const array APPLE_EXTERNAL = [
        'appId' => self::APPLE_APP_ID,
        'bundleId' => 'io.example.app',
        'version' => '1.4.0',
    ];

    private const string GOOGLE_EMAIL = 'publisher@example-project.iam.gserviceaccount.com';

    private const string GOOGLE_PACKAGE = 'io.example.app';

    private const string GOOGLE_ENDPOINT = 'https://play.test/v1/mock/distribution/google';

    private const string GOOGLE_TOKEN = self::GOOGLE_ENDPOINT . '/token';

    private const string GOOGLE_EDITS = self::GOOGLE_ENDPOINT . '/androidpublisher/v3/applications/io.example.app/edits';

    private const string GOOGLE_EDIT_ID = '06155296137469138127';

    private const string GOOGLE_EDIT = self::GOOGLE_EDITS . '/' . self::GOOGLE_EDIT_ID;

    private const string GOOGLE_LISTING = self::GOOGLE_EDIT . '/listings/en-US';

    private const string GOOGLE_UPLOAD = self::GOOGLE_ENDPOINT . '/upload/androidpublisher/v3/applications/io.example.app/edits/06155296137469138127/listings/en-US';

    private const string GOOGLE_COMMIT = self::GOOGLE_EDIT . ':commit?changesInReviewBehavior=ERROR_IF_IN_REVIEW';

    private const string GOOGLE_PHONE = 'phoneScreenshots';

    private const string GOOGLE_TABLET = 'tenInchScreenshots';

    private const array GOOGLE_SCREENSHOTS = [
        'phone-1.png' => 'phone screenshot one',
        'phone-2.png' => 'phone screenshot two',
        'tablet-1.png' => 'tablet screenshot one',
    ];

    private const array GOOGLE_COMMITTED = ['packageName' => self::GOOGLE_PACKAGE, 'committed' => true];

    private const array GOOGLE_UPLOADED = [
        'packageName' => self::GOOGLE_PACKAGE,
        'editId' => self::GOOGLE_EDIT_ID,
        'expiryTimeSeconds' => 1_795_000_000,
        'versionCode' => 88,
        'sha256' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
    ];

    private const int GOOGLE_NOW = 1_800_000_000;

    private static string $applePrivateKey = '';

    private static ?string $googleKey = null;

    private string $appleDirectory = '';

    private string $googleDirectory = '';

    #[BeforeClass]
    public static function appleCreateKey(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);

        self::$applePrivateKey = $private;
    }

    #[Before]
    public function appleCreateScreenshots(): void
    {
        $this->appleDirectory = sys_get_temp_dir() . '/distribution-listing-' . bin2hex(random_bytes(6));
        mkdir($this->appleDirectory, 0o700, true);
        file_put_contents($this->appleDirectory . '/first.png', self::APPLE_FIRST_SCREENSHOT);
        file_put_contents($this->appleDirectory . '/second.png', self::APPLE_SECOND_SCREENSHOT);
    }

    #[After]
    public function appleRemoveScreenshots(): void
    {
        foreach (['first.png', 'second.png'] as $name) {
            if (is_file($this->appleDirectory . '/' . $name)) {
                unlink($this->appleDirectory . '/' . $name);
            }
        }

        if (is_dir($this->appleDirectory)) {
            rmdir($this->appleDirectory);
        }
    }

    #[Before]
    public function googlePrepare(): void
    {
        $this->googleDirectory = sys_get_temp_dir() . '/distribution-listings-' . bin2hex(random_bytes(6));
        mkdir($this->googleDirectory, 0o700, true);

        foreach (self::GOOGLE_SCREENSHOTS as $name => $contents) {
            file_put_contents($this->googleDirectory . '/' . $name, $contents);
        }
    }

    #[After]
    public function googleCleanup(): void
    {
        foreach (array_keys(self::GOOGLE_SCREENSHOTS) as $name) {
            $path = $this->googleDirectory . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->googleDirectory)) {
            rmdir($this->googleDirectory);
        }
    }

    public function testAppleListingFirstSyncWritesTextUploadsAndPlacesScreenshots(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-info-infos'),
            $this->appleReply('app-info-localizations'),
            $this->appleReply('app-info-localization'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('app-store-version-localization'),
            $this->appleReply('asset-library'),
            $this->appleReply('asset-image', 201),
            $this->applePage(200, ''),
            $this->appleReply('asset-image-ready'),
            $this->appleReply('asset-image-second', 201),
            $this->applePage(200, ''),
            $this->appleReply('asset-image-second-ready'),
            $this->appleReply('asset-placements'),
            $this->applePage(204, ''),
            $this->appleReply('asset-placement', 201),
            $this->appleReply('asset-placement-second', 201),
            $this->appleReply('asset-placement-ordering-request', 201),
        );

        $external = $this->appleStore($transport)->listing($this->appleListing([
            Listing::FIELD_NAME => 'Example Pro',
            Listing::FIELD_SUBTITLE => 'Sync everything',
            Listing::FIELD_PRIVACY_URL => 'https://example.io/privacy',
            Listing::FIELD_SHORT_DESCRIPTION => 'Ignored by the App Store',
            Listing::FIELD_DESCRIPTION => 'A faster example app.',
            Listing::FIELD_KEYWORDS => 'example',
            Listing::FIELD_PROMOTIONAL_TEXT => 'Now with sync.',
        ]), self::APPLE_EXTERNAL);

        $this->assertSame([
            ...self::APPLE_EXTERNAL,
            'appInfoId' => self::APPLE_APP_INFO_ID,
            'libraryId' => self::APPLE_LIBRARY_ID,
            'assets' => [
                $this->appleDigest(self::APPLE_FIRST_SCREENSHOT) => self::APPLE_FIRST_IMAGE_ID,
                $this->appleDigest(self::APPLE_SECOND_SCREENSHOT) => self::APPLE_SECOND_IMAGE_ID,
            ],
            'complete' => true,
        ], $external);

        $this->assertSame([
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appStoreVersions',
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appInfos',
            'GET /v1/appInfos/' . self::APPLE_APP_INFO_ID . '/appInfoLocalizations',
            'PATCH /v1/appInfoLocalizations/' . self::APPLE_APP_INFO_LOCALIZATION_ID,
            'GET /v1/appStoreVersions/' . self::APPLE_VERSION_ID . '/appStoreVersionLocalizations',
            'PATCH /v1/appStoreVersionLocalizations/' . self::APPLE_LOCALIZATION_ID,
            'GET /v1/apps/' . self::APPLE_APP_ID . '/assetLibrary',
            'POST /v1/appAssetLibraryImages',
            'PUT https://storage.test/apple/asset/' . self::APPLE_FIRST_IMAGE_ID . '/1',
            'PATCH /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
            'POST /v1/appAssetLibraryImages',
            'PUT https://storage.test/apple/asset/' . self::APPLE_SECOND_IMAGE_ID . '/1',
            'PATCH /v1/appAssetLibraryImages/' . self::APPLE_SECOND_IMAGE_ID,
            'GET /v1/appStoreVersionLocalizations/' . self::APPLE_LOCALIZATION_ID . '/placements',
            'DELETE /v1/appAssetLibraryPlacements/' . self::APPLE_STALE_PLACEMENT_ID,
            'POST /v1/appAssetLibraryPlacements',
            'POST /v1/appAssetLibraryPlacements',
            'POST /v1/appAssetLibraryPlacementOrderingRequests',
        ], $this->appleCalls($transport));

        $requests = $transport->requests();
        $this->assertSame(['limit' => '200'], $this->appleQuery($requests[1]));
        $this->assertSame([
            'data' => [
                'type' => 'appInfoLocalizations',
                'id' => self::APPLE_APP_INFO_LOCALIZATION_ID,
                'attributes' => ['name' => 'Example Pro', 'subtitle' => 'Sync everything'],
            ],
        ], $this->appleBody($requests[3]));
        $this->assertSame([
            'data' => [
                'type' => 'appStoreVersionLocalizations',
                'id' => self::APPLE_LOCALIZATION_ID,
                'attributes' => ['description' => 'A faster example app.', 'promotionalText' => 'Now with sync.'],
            ],
        ], $this->appleBody($requests[5]));
        $this->assertSame([
            'data' => [
                'type' => 'appAssetLibraryImages',
                'attributes' => [
                    'category' => 'APP_SCREENSHOTS_AND_PREVIEWS',
                    'fileName' => 'first.png',
                    'fileSize' => 12,
                    'referenceName' => $this->appleDigest(self::APPLE_FIRST_SCREENSHOT),
                ],
                'relationships' => [
                    'assetLibrary' => ['data' => ['type' => 'appAssetLibraries', 'id' => self::APPLE_LIBRARY_ID]],
                ],
            ],
        ], $this->appleBody($requests[7]));
        $this->assertFalse($requests[8]->hasHeader('Authorization'));
        $this->assertSame('application/octet-stream', $requests[8]->getHeaderLine('Content-Type'));
        $this->assertSame(self::APPLE_FIRST_SCREENSHOT, (string) $requests[8]->getBody());
        $this->assertSame([
            'data' => [
                'type' => 'appAssetLibraryImages',
                'id' => self::APPLE_FIRST_IMAGE_ID,
                'attributes' => ['uploaded' => true],
            ],
        ], $this->appleBody($requests[9]));
        $this->assertSame(self::APPLE_SECOND_SCREENSHOT, (string) $requests[11]->getBody());
        $this->assertSame([
            'include' => 'image',
            'sort' => 'placementGroupPosition',
            'limit' => '200',
        ], $this->appleQuery($requests[13]));
        $this->assertSame([
            'data' => [
                'type' => 'appAssetLibraryPlacements',
                'attributes' => ['placementType' => 'APP_SCREENSHOT', 'placementGroup' => self::APPLE_GROUP],
                'relationships' => [
                    'image' => ['data' => ['type' => 'appAssetLibraryImages', 'id' => self::APPLE_FIRST_IMAGE_ID]],
                    'appStoreVersionLocalization' => ['data' => ['type' => 'appStoreVersionLocalizations', 'id' => self::APPLE_LOCALIZATION_ID]],
                ],
            ],
        ], $this->appleBody($requests[15]));
        $this->assertSame([
            'data' => [
                'type' => 'appAssetLibraryPlacementOrderingRequests',
                'attributes' => ['placementGroup' => self::APPLE_GROUP],
                'relationships' => [
                    'orderedPlacements' => ['data' => [
                        ['type' => 'appAssetLibraryPlacements', 'id' => self::APPLE_FIRST_PLACEMENT_ID],
                        ['type' => 'appAssetLibraryPlacements', 'id' => self::APPLE_SECOND_PLACEMENT_ID],
                    ]],
                    'appStoreVersionLocalization' => ['data' => ['type' => 'appStoreVersionLocalizations', 'id' => self::APPLE_LOCALIZATION_ID]],
                ],
            ],
        ], $this->appleBody($requests[17]));
    }

    public function testAppleListingResyncWithoutChangesOnlyReads(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-info-infos'),
            $this->appleReply('app-info-localizations'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-image-ready'),
            $this->appleReply('asset-image-second-ready'),
            $this->appleReply('asset-placements-placed'),
        );

        $synced = $this->appleSynced();

        $external = $this->appleStore($transport)->listing($this->appleListing([
            Listing::FIELD_NAME => 'Example',
            Listing::FIELD_PRIVACY_URL => 'https://example.io/privacy',
            Listing::FIELD_DESCRIPTION => 'An example app.',
            Listing::FIELD_KEYWORDS => 'example',
        ]), $synced);

        $this->assertSame([...$synced, 'complete' => true], $external);
        $this->assertSame([
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appStoreVersions',
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appInfos',
            'GET /v1/appInfos/' . self::APPLE_APP_INFO_ID . '/appInfoLocalizations',
            'GET /v1/appStoreVersions/' . self::APPLE_VERSION_ID . '/appStoreVersionLocalizations',
            'GET /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
            'GET /v1/appAssetLibraryImages/' . self::APPLE_SECOND_IMAGE_ID,
            'GET /v1/appStoreVersionLocalizations/' . self::APPLE_LOCALIZATION_ID . '/placements',
        ], $this->appleCalls($transport));
    }

    public function testAppleListingReordersPlacements(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-image-ready'),
            $this->appleReply('asset-image-second-ready'),
            $this->appleReply('asset-placements-reversed'),
            $this->appleReply('asset-placement-ordering-request', 201),
        );

        $this->appleStore($transport)->listing($this->appleListing([]), $this->appleSynced());

        $this->assertSame([
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appStoreVersions',
            'GET /v1/appStoreVersions/' . self::APPLE_VERSION_ID . '/appStoreVersionLocalizations',
            'GET /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
            'GET /v1/appAssetLibraryImages/' . self::APPLE_SECOND_IMAGE_ID,
            'GET /v1/appStoreVersionLocalizations/' . self::APPLE_LOCALIZATION_ID . '/placements',
            'POST /v1/appAssetLibraryPlacementOrderingRequests',
        ], $this->appleCalls($transport));

        $this->assertSame([
            'data' => [
                'type' => 'appAssetLibraryPlacementOrderingRequests',
                'attributes' => ['placementGroup' => self::APPLE_GROUP],
                'relationships' => [
                    'orderedPlacements' => ['data' => [
                        ['type' => 'appAssetLibraryPlacements', 'id' => self::APPLE_FIRST_PLACEMENT_ID],
                        ['type' => 'appAssetLibraryPlacements', 'id' => self::APPLE_SECOND_PLACEMENT_ID],
                    ]],
                    'appStoreVersionLocalization' => ['data' => ['type' => 'appStoreVersionLocalizations', 'id' => self::APPLE_LOCALIZATION_ID]],
                ],
            ],
        ], $this->appleBody($transport->requests()[5]));
    }

    public function testAppleListingDropsDuplicatePlacementsAndAcceptsApprovedImages(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-image-approved'),
            $this->appleReply('asset-image-second-ready'),
            $this->appleReply('asset-placements-duplicated'),
            $this->applePage(204, ''),
        );

        $external = $this->appleStore($transport)->listing($this->appleListing([]), $this->appleSynced());

        $this->assertTrue($external['complete']);
        $this->assertSame(
            'DELETE /v1/appAssetLibraryPlacements/' . self::APPLE_STALE_PLACEMENT_ID,
            $this->appleCalls($transport)[5],
        );
        $this->assertCount(6, $transport->requests());
    }

    public function testAppleListingFailedImageThrowsInvalidWithStateDetails(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-image-failed'),
        );

        $exception = $this->appleCatch(fn (): array => $this->appleStore($transport)->listing($this->appleListing([]), $this->appleSynced()));

        $this->assertInstanceOf(Invalid::class, $exception);
        $this->assertSame('App Store Connect could not process the screenshot launch-screen', $exception->getMessage());
        $this->assertSame(
            ['IMAGE_INCORRECT_DIMENSIONS', 'IMAGE_ALPHA_NOT_ALLOWED'],
            array_map(fn (Issue $issue): string => $issue->code, $exception->getIssues()),
        );
        $this->assertSame('The dimensions of one or more screenshots are wrong.', $exception->getIssues()[0]->message);
        $this->assertCount(3, $transport->requests());
    }

    public function testAppleListingProcessingImageReturnsIncompleteWithoutPlacing(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-library'),
            $this->appleReply('asset-image', 201),
            $this->applePage(200, ''),
            $this->appleReply('asset-image-processing'),
        );

        $external = $this->appleStore($transport)->listing(
            $this->appleListing([], [$this->appleScreenshot('first.png', self::APPLE_FIRST_SCREENSHOT)]),
            self::APPLE_EXTERNAL,
        );

        $this->assertSame([
            ...self::APPLE_EXTERNAL,
            'libraryId' => self::APPLE_LIBRARY_ID,
            'assets' => [$this->appleDigest(self::APPLE_FIRST_SCREENSHOT) => self::APPLE_FIRST_IMAGE_ID],
            'complete' => false,
        ], $external);
        $this->assertCount(6, $transport->requests());
    }

    public function testAppleListingReuploadsAnImageTheLibraryLost(): void
    {
        $digest = $this->appleDigest(self::APPLE_FIRST_SCREENSHOT);
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('error-not-found', 404),
            $this->appleReply('asset-image', 201),
            $this->applePage(200, ''),
            $this->appleReply('asset-image-ready'),
            $this->appleReply('asset-placements-placed'),
            $this->applePage(204, ''),
        );

        $external = $this->appleStore($transport)->listing(
            $this->appleListing([], [$this->appleScreenshot('first.png', self::APPLE_FIRST_SCREENSHOT)]),
            [...self::APPLE_EXTERNAL, 'libraryId' => self::APPLE_LIBRARY_ID, 'assets' => [$digest => self::APPLE_LOST_IMAGE_ID]],
        );

        $this->assertSame([$digest => self::APPLE_FIRST_IMAGE_ID], $external['assets']);
        $this->assertSame([
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appStoreVersions',
            'GET /v1/appStoreVersions/' . self::APPLE_VERSION_ID . '/appStoreVersionLocalizations',
            'GET /v1/appAssetLibraryImages/' . self::APPLE_LOST_IMAGE_ID,
            'POST /v1/appAssetLibraryImages',
            'PUT https://storage.test/apple/asset/' . self::APPLE_FIRST_IMAGE_ID . '/1',
            'PATCH /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
            'GET /v1/appStoreVersionLocalizations/' . self::APPLE_LOCALIZATION_ID . '/placements',
            'DELETE /v1/appAssetLibraryPlacements/' . self::APPLE_SECOND_PLACEMENT_ID,
        ], $this->appleCalls($transport));
    }

    public function testAppleListingOperationBeyondTheScreenshotThrowsUnavailable(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-library'),
            $this->appleReply('asset-image-oversized', 201),
            $this->applePage(204, ''),
        );

        $exception = $this->appleCatch(fn (): array => $this->appleStore($transport)->listing(
            $this->appleListing([], [$this->appleScreenshot('first.png', self::APPLE_FIRST_SCREENSHOT)]),
            self::APPLE_EXTERNAL,
        ));

        $this->assertInstanceOf(Unavailable::class, $exception);
        $this->assertSame('DELETE /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID, $this->appleCalls($transport)[4]);
        $this->assertCount(5, $transport->requests());
    }

    public function testAppleListingFailedPartUploadDiscardsTheImage(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-library'),
            $this->appleReply('asset-image', 201),
            $this->applePage(500, ''),
            $this->applePage(204, ''),
        );

        $exception = $this->appleCatch(fn (): array => $this->appleStore($transport)->listing(
            $this->appleListing([], [$this->appleScreenshot('first.png', self::APPLE_FIRST_SCREENSHOT)]),
            self::APPLE_EXTERNAL,
        ));

        $this->assertInstanceOf(Unavailable::class, $exception);
        $this->assertSame('App Store Connect storage returned 500 for part 1', $exception->getMessage());
        $this->assertSame([
            'GET /v1/apps/' . self::APPLE_APP_ID . '/appStoreVersions',
            'GET /v1/appStoreVersions/' . self::APPLE_VERSION_ID . '/appStoreVersionLocalizations',
            'GET /v1/apps/' . self::APPLE_APP_ID . '/assetLibrary',
            'POST /v1/appAssetLibraryImages',
            'PUT https://storage.test/apple/asset/' . self::APPLE_FIRST_IMAGE_ID . '/1',
            'DELETE /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
        ], $this->appleCalls($transport));
    }

    public function testAppleListingFailedCommitDiscardsTheImageAndKeepsTheError(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-library'),
            $this->appleReply('asset-image', 201),
            $this->applePage(200, ''),
            $this->appleReply('error-state', 409),
            $this->appleReply('error-server', 500),
        );

        $exception = $this->appleCatch(fn (): array => $this->appleStore($transport)->listing(
            $this->appleListing([], [$this->appleScreenshot('first.png', self::APPLE_FIRST_SCREENSHOT)]),
            self::APPLE_EXTERNAL,
        ));

        $this->assertInstanceOf(Conflict::class, $exception);
        $this->assertSame([
            'PATCH /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
            'DELETE /v1/appAssetLibraryImages/' . self::APPLE_FIRST_IMAGE_ID,
        ], \array_slice($this->appleCalls($transport), 5));
    }

    #[DataProvider('appleReadyStates')]
    public function testAppleListingAcceptsEveryReadyImageState(string $state): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleImage($state),
            $this->appleReply('asset-image-second-ready'),
            $this->appleReply('asset-placements-placed'),
        );

        $external = $this->appleStore($transport)->listing($this->appleListing([]), $this->appleSynced());

        $this->assertTrue($external['complete']);
        $this->assertCount(5, $transport->requests());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function appleReadyStates(): array
    {
        return [
            'complete' => ['COMPLETE'],
            'prepare for submission' => ['PREPARE_FOR_SUBMISSION'],
            'ready for review' => ['READY_FOR_REVIEW'],
            'waiting for review' => ['WAITING_FOR_REVIEW'],
            'in review' => ['IN_REVIEW'],
            'accepted' => ['ACCEPTED'],
            'approved' => ['APPROVED'],
        ];
    }

    #[DataProvider('appleUnplaceableStates')]
    public function testAppleListingImageInAnUnplaceableStateThrowsUnavailable(string $state): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleImage($state),
        );

        $exception = $this->appleCatch(fn (): array => $this->appleStore($transport)->listing($this->appleListing([]), $this->appleSynced()));

        $this->assertInstanceOf(Unavailable::class, $exception);
        $this->assertSame(
            'App Store Connect returned the screenshot ' . self::APPLE_FIRST_IMAGE_ID . " in state {$state}, which cannot be placed",
            $exception->getMessage(),
        );
        $this->assertCount(3, $transport->requests());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function appleUnplaceableStates(): array
    {
        return [
            'rejected' => ['REJECTED'],
            'archived' => ['ARCHIVED'],
            'unknown' => ['QUARANTINED'],
        ];
    }

    public function testAppleListingPlacementRefusedByTheStoreThrowsConflict(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('asset-image-ready'),
            $this->appleReply('asset-image-second-ready'),
            $this->appleReply('asset-placements'),
            $this->applePage(204, ''),
            $this->appleReply('asset-placement-error-state', 409),
        );

        $this->expectException(Conflict::class);

        $this->appleStore($transport)->listing($this->appleListing([]), $this->appleSynced());
    }

    public function testAppleListingOnALockedVersionThrowsConflict(): void
    {
        $transport = new Transport($this->appleReply('app-store-versions-submitted'));

        $exception = $this->appleCatch(fn (): array => $this->appleStore($transport)->listing($this->appleListing([]), self::APPLE_EXTERNAL));

        $this->assertInstanceOf(Conflict::class, $exception);
        $this->assertSame('STATE_ERROR', $exception->getIssues()[0]->code);
        $this->assertCount(1, $transport->requests());
    }

    public function testAppleListingWithoutEditableAppInfoThrowsConflict(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-info-infos-released'),
        );

        $this->expectException(Conflict::class);

        $this->appleStore($transport)->listing(
            new Listing('io.example.app', 'en-US', [Listing::FIELD_SUBTITLE => 'Sync everything']),
            self::APPLE_EXTERNAL,
        );
    }

    public function testAppleListingCreatesMissingLocalizations(): void
    {
        $transport = new Transport(
            $this->appleReply('app-store-versions'),
            $this->appleReply('app-info-infos'),
            $this->appleReply('app-info-localizations'),
            $this->appleReply('app-info-localization', 201),
            $this->appleReply('app-store-version-localizations'),
            $this->appleReply('app-store-version-localization', 201),
        );

        $external = $this->appleStore($transport)->listing(
            new Listing('io.example.app', 'de-DE', [Listing::FIELD_NAME => 'Beispiel', Listing::FIELD_DESCRIPTION => 'Eine Beispiel-App.']),
            self::APPLE_EXTERNAL,
        );

        $this->assertSame([...self::APPLE_EXTERNAL, 'appInfoId' => self::APPLE_APP_INFO_ID, 'complete' => true], $external);

        $requests = $transport->requests();
        $this->assertSame('POST', $requests[3]->getMethod());
        $this->assertSame([
            'data' => [
                'type' => 'appInfoLocalizations',
                'attributes' => ['locale' => 'de-DE', 'name' => 'Beispiel'],
                'relationships' => ['appInfo' => ['data' => ['type' => 'appInfos', 'id' => self::APPLE_APP_INFO_ID]]],
            ],
        ], $this->appleBody($requests[3]));
        $this->assertSame('POST', $requests[5]->getMethod());
        $this->assertSame([
            'data' => [
                'type' => 'appStoreVersionLocalizations',
                'attributes' => ['locale' => 'de-DE', 'description' => 'Eine Beispiel-App.'],
                'relationships' => ['appStoreVersion' => ['data' => ['type' => 'appStoreVersions', 'id' => self::APPLE_VERSION_ID]]],
            ],
        ], $this->appleBody($requests[5]));
    }

    public function testAppleListingWithoutAppIdThrowsInvalid(): void
    {
        $transport = new Transport();

        $this->expectException(Invalid::class);

        try {
            $this->appleStore($transport)->listing($this->appleListing([]), ['version' => '1.4.0']);
        } finally {
            $this->assertSame([], $transport->requests());
        }
    }

    public function testGoogleListingPatchesTextAndKeepsUnchangedImages(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/listing-updated'),
            Transport::fixture('Google/images'),
            Transport::fixture('Google/commit'),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [
            Listing::FIELD_NAME => 'Example Pro',
            Listing::FIELD_SUBTITLE => 'Ignored by Google Play',
            Listing::FIELD_SHORT_DESCRIPTION => 'A better example app',
            Listing::FIELD_DESCRIPTION => 'The example app, now with more examples.',
            Listing::FIELD_KEYWORDS => 'ignored,keywords',
            Listing::FIELD_SUPPORT_URL => 'https://example.com/support',
        ], [
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-1.png'),
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-2.png'),
        ], $this->googleDevice());

        $external = $this->googleStore($transport)->listing($listing, []);

        $this->assertSame(self::GOOGLE_COMMITTED, $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'PATCH', 'GET', 'POST'], $this->googleMethods($requests));
        $this->assertSame([
            self::GOOGLE_TOKEN,
            self::GOOGLE_EDITS,
            self::GOOGLE_LISTING,
            self::GOOGLE_LISTING . '/' . self::GOOGLE_PHONE,
            self::GOOGLE_COMMIT,
        ], $this->googleUris($requests));

        $this->assertSame('application/json', $requests[2]->getHeaderLine('Content-Type'));
        $this->assertSame([
            'title' => 'Example Pro',
            'shortDescription' => 'A better example app',
            'fullDescription' => 'The example app, now with more examples.',
        ], json_decode((string) $requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testGoogleListingCreatesMissingLanguage(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/listing-not-found', 404),
            Transport::fixture('Google/listing-updated'),
            Transport::fixture('Google/commit'),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [Listing::FIELD_NAME => 'Example Pro']);

        $this->assertSame(self::GOOGLE_COMMITTED, $this->googleStore($transport)->listing($listing, []));

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'PATCH', 'PUT', 'POST'], $this->googleMethods($requests));
        $this->assertSame([self::GOOGLE_LISTING, self::GOOGLE_LISTING], $this->googleUris(\array_slice($requests, 2, 2)));
        $this->assertSame(['title' => 'Example Pro'], json_decode((string) $requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(
            ['language' => 'en-US', 'title' => 'Example Pro'],
            json_decode((string) $requests[3]->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testGoogleListingReplacesChangedImagesInOrder(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/images-stale'),
            Transport::fixture('Google/images-deleted'),
            Transport::fixture('Google/images-uploaded'),
            Transport::fixture('Google/images-uploaded'),
            Transport::fixture('Google/images-empty'),
            Transport::fixture('Google/images-deleted'),
            Transport::fixture('Google/images-uploaded'),
            Transport::fixture('Google/commit'),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [], [
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-1.png'),
            $this->googleScreenshot(self::GOOGLE_TABLET, 'tablet-1.png'),
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-2.png'),
        ], $this->googleDevice());

        $this->assertSame(self::GOOGLE_COMMITTED, $this->googleStore($transport)->listing($listing, []));

        $phone = self::GOOGLE_LISTING . '/' . self::GOOGLE_PHONE;
        $tablet = self::GOOGLE_LISTING . '/' . self::GOOGLE_TABLET;
        $phoneUpload = self::GOOGLE_UPLOAD . '/' . self::GOOGLE_PHONE . '?uploadType=media';
        $tabletUpload = self::GOOGLE_UPLOAD . '/' . self::GOOGLE_TABLET . '?uploadType=media';

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'DELETE', 'POST', 'POST', 'GET', 'DELETE', 'POST', 'POST'], $this->googleMethods($requests));
        $this->assertSame([
            self::GOOGLE_TOKEN,
            self::GOOGLE_EDITS,
            $phone,
            $phone,
            $phoneUpload,
            $phoneUpload,
            $tablet,
            $tablet,
            $tabletUpload,
            self::GOOGLE_COMMIT,
        ], $this->googleUris($requests));

        $this->assertGoogleImage($requests[4], 'phone-1.png');
        $this->assertGoogleImage($requests[5], 'phone-2.png');
        $this->assertGoogleImage($requests[8], 'tablet-1.png');
    }

    public function testGoogleListingCommitInReviewThrowsConflictAndDiscardsEdit(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/listing-updated'),
            Transport::fixture('Google/error-in-review', 400),
            new Response(204),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [Listing::FIELD_NAME => 'Example Pro']);

        try {
            $this->googleStore($transport)->listing($listing, []);
            $this->fail('A commit while changes are in review must throw Conflict');
        } catch (Conflict $conflict) {
            $this->assertSame('CHANGES_ALREADY_IN_REVIEW', $conflict->getIssues()[0]->code);
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'PATCH', 'POST', 'DELETE'], $this->googleMethods($requests));
        $this->assertSame([self::GOOGLE_COMMIT, self::GOOGLE_EDIT], $this->googleUris(\array_slice($requests, 3)));
    }

    public function testGoogleListingCommitsWithoutReviewWhenRequired(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/listing-updated'),
            Transport::fixture('Google/error-not-sent-for-review', 400),
            Transport::fixture('Google/commit'),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [Listing::FIELD_NAME => 'Example Pro']);

        $external = $this->googleStore($transport)->listing($listing, ['packageName' => self::GOOGLE_PACKAGE]);

        $this->assertSame([...self::GOOGLE_COMMITTED, 'changesNotSentForReview' => true], $external);

        $requests = $transport->requests();
        $this->assertSame([self::GOOGLE_COMMIT, self::GOOGLE_COMMIT . '&changesNotSentForReview=true'], $this->googleUris(\array_slice($requests, 3)));
    }

    public function testGoogleListingWritesIntoOpenUploadEditWithoutCommitting(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/listing-updated'),
            Transport::fixture('Google/images'),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [Listing::FIELD_NAME => 'Example Pro'], [
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-1.png'),
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-2.png'),
        ], $this->googleDevice());

        $external = $this->googleStore($transport)->listing($listing, self::GOOGLE_UPLOADED);

        $this->assertSame([...self::GOOGLE_UPLOADED, 'committed' => false], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'PATCH', 'GET'], $this->googleMethods($requests));
        $this->assertSame(
            [self::GOOGLE_TOKEN, self::GOOGLE_LISTING, self::GOOGLE_LISTING . '/' . self::GOOGLE_PHONE],
            $this->googleUris($requests),
        );
    }

    public function testGoogleListingFailureInUploadEditKeepsEdit(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/error-invalid', 400),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [Listing::FIELD_NAME => 'Example Pro']);

        try {
            $this->googleStore($transport)->listing($listing, self::GOOGLE_UPLOADED);
            $this->fail('A rejected listing must throw Invalid');
        } catch (Invalid) {
        }

        $this->assertSame(['POST', 'PATCH'], $this->googleMethods($transport->requests()));
    }

    public function testGoogleListingWithoutOpenUploadEditCommitsOwnEdit(): void
    {
        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [Listing::FIELD_NAME => 'Example Pro']);

        $externals = [
            'released upload' => [...self::GOOGLE_UPLOADED, 'track' => 'production', 'committed' => true],
            'another package' => [...self::GOOGLE_UPLOADED, 'packageName' => 'io.example.other'],
            'no upload' => ['packageName' => self::GOOGLE_PACKAGE],
        ];

        foreach ($externals as $case => $external) {
            $transport = new Transport(
                Transport::fixture('Google/token'),
                Transport::fixture('Google/edit'),
                Transport::fixture('Google/listing-updated'),
                Transport::fixture('Google/commit'),
            );

            $this->assertSame(self::GOOGLE_COMMITTED, $this->googleStore($transport)->listing($listing, $external), $case);
            $this->assertSame(
                [self::GOOGLE_TOKEN, self::GOOGLE_EDITS, self::GOOGLE_LISTING, self::GOOGLE_COMMIT],
                $this->googleUris($transport->requests()),
                $case,
            );
        }
    }

    public function testGoogleListingDiscardsEditWhenUploadFails(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/images-stale'),
            Transport::fixture('Google/images-deleted'),
            Transport::fixture('Google/images-invalid', 400),
            new Response(204),
        );

        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [], [
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-1.png'),
            $this->googleScreenshot(self::GOOGLE_PHONE, 'phone-2.png'),
        ], $this->googleDevice());

        try {
            $this->googleStore($transport)->listing($listing, []);
            $this->fail('A rejected image must throw Invalid');
        } catch (Invalid $invalid) {
            $this->assertSame('imageInvalid', $invalid->getIssues()[0]->code);
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'DELETE', 'POST', 'DELETE'], $this->googleMethods($requests));
        $this->assertSame(self::GOOGLE_EDIT, (string) $requests[5]->getUri());
    }

    public function testGoogleListingRejectsOversizedImageBeforeSending(): void
    {
        $transport = new Transport();

        $screenshot = [...$this->googleScreenshot(self::GOOGLE_PHONE, 'phone-1.png'), 'size' => Store\Google::IMAGE_LIMIT + 1];
        $listing = new Listing(self::GOOGLE_PACKAGE, 'en-US', [], [$screenshot], $this->googleDevice());

        try {
            $this->googleStore($transport)->listing($listing, []);
            $this->fail('An image over the Google Play limit must throw Invalid');
        } catch (Invalid $invalid) {
            $this->assertStringContainsString((string) Store\Google::IMAGE_LIMIT, $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    /**
     * @param array<string, string> $fields
     * @param list<array{set: string, path: string, size: int, width: int, height: int, digest: string}>|null $screenshots
     */
    private function appleListing(array $fields, ?array $screenshots = null): Listing
    {
        $screenshots ??= [
            $this->appleScreenshot('first.png', self::APPLE_FIRST_SCREENSHOT),
            $this->appleScreenshot('second.png', self::APPLE_SECOND_SCREENSHOT),
        ];

        return new Listing('io.example.app', 'en-US', $fields, $screenshots, new Local($this->appleDirectory));
    }

    /**
     * @return array{set: string, path: string, size: int, width: int, height: int, digest: string}
     */
    private function appleScreenshot(string $name, string $contents): array
    {
        return [
            'set' => self::APPLE_GROUP,
            'path' => $this->appleDirectory . '/' . $name,
            'size' => \strlen($contents),
            'width' => 1320,
            'height' => 2868,
            'digest' => $this->appleDigest($contents),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appleSynced(): array
    {
        return [
            ...self::APPLE_EXTERNAL,
            'appInfoId' => self::APPLE_APP_INFO_ID,
            'libraryId' => self::APPLE_LIBRARY_ID,
            'assets' => [
                $this->appleDigest(self::APPLE_FIRST_SCREENSHOT) => self::APPLE_FIRST_IMAGE_ID,
                $this->appleDigest(self::APPLE_SECOND_SCREENSHOT) => self::APPLE_SECOND_IMAGE_ID,
            ],
        ];
    }

    private function appleDigest(string $contents): string
    {
        return hash('sha256', $contents);
    }

    private function appleCatch(callable $operation): Exception
    {
        try {
            $operation();
        } catch (Exception $exception) {
            return $exception;
        }

        $this->fail('The listing did not throw');
    }

    private function appleStore(Transport $transport): Apple\Frozen
    {
        return new Apple\Frozen(new Credential\Apple(self::APPLE_KEY_ID, self::APPLE_ISSUER_ID, self::$applePrivateKey), $transport, self::APPLE_NOW, self::APPLE_ENDPOINT);
    }

    private function appleReply(string $fixture, int $status = 200): ResponseInterface
    {
        return Transport::fixture("Apple/{$fixture}", $status, ['X-Rate-Limit' => 'user-hour-lim:3500;user-hour-rem:3499;']);
    }

    private function appleImage(string $state): ResponseInterface
    {
        $image = (string) $this->appleReply('asset-image-approved')->getBody();

        return $this->applePage(200, str_replace('"state": "APPROVED"', "\"state\": \"{$state}\"", $image));
    }

    private function applePage(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body));
    }

    /**
     * @return list<string>
     */
    private function appleCalls(Transport $transport): array
    {
        return array_map(
            function (RequestInterface $request): string {
                $path = (string) $request->getUri()->withQuery('');

                return $request->getMethod() . ' ' . (str_starts_with($path, self::APPLE_ENDPOINT) ? substr($path, \strlen(self::APPLE_ENDPOINT)) : $path);
            },
            $transport->requests(),
        );
    }

    /**
     * @return array<mixed>
     */
    private function appleQuery(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * @return array<mixed>
     */
    private function appleBody(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }

    private function assertGoogleImage(RequestInterface $request, string $name): void
    {
        $contents = self::GOOGLE_SCREENSHOTS[$name];

        $this->assertSame('image/*', $request->getHeaderLine('Content-Type'));
        $this->assertSame((string) \strlen($contents), $request->getHeaderLine('Content-Length'));
        $this->assertSame('Bearer ya29.c.fixture-access-token', $request->getHeaderLine('Authorization'));
        $this->assertSame($contents, (string) $request->getBody());
    }

    private function googleStore(Transport $transport): Google\Frozen
    {
        return new Google\Frozen(new Credential\Google(self::GOOGLE_EMAIL, $this->googleKey()), $transport, self::GOOGLE_NOW, self::GOOGLE_ENDPOINT, self::GOOGLE_TOKEN);
    }

    private function googleDevice(): Local
    {
        return new Local($this->googleDirectory);
    }

    /**
     * @return array{set: string, path: string, size: int, width: int, height: int, digest: string}
     */
    private function googleScreenshot(string $set, string $name): array
    {
        $contents = self::GOOGLE_SCREENSHOTS[$name];

        return [
            'set' => $set,
            'path' => $this->googleDirectory . '/' . $name,
            'size' => \strlen($contents),
            'width' => 1080,
            'height' => 1920,
            'digest' => hash('sha256', $contents),
        ];
    }

    private function googleKey(): string
    {
        if (self::$googleKey !== null) {
            return self::$googleKey;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || !openssl_pkey_export($key, $private) || !\is_string($private)) {
            throw new \LogicException('RSA key generation failed');
        }

        return self::$googleKey = $private;
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<string>
     */
    private function googleMethods(array $requests): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getMethod(), $requests);
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<string>
     */
    private function googleUris(array $requests): array
    {
        return array_map(static fn (RequestInterface $request): string => (string) $request->getUri(), $requests);
    }
}
