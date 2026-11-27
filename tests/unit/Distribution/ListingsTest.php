<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Listings;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Submissions;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\ListingDatabase;
use Tests\Unit\Distribution\Fakes\ListingStore;
use Tests\Unit\Distribution\Fakes\RecordingSchedules;
use Tests\Unit\Distribution\Fakes\ScriptedStores;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Listing;
use Utopia\Query\Method;
use Utopia\Queue\Queue;
use Utopia\Storage\Device\Local;

final class ListingsTest extends TestCase
{
    private const string QUEUE = 'v1-distribution-submissions';

    private const string PACKAGE = 'io.example.app';

    private const string BUNDLE = 'io.example.ios';

    private const string EDIT = 'edit-1';

    private ListingDatabase $database;

    private ListingStore $store;

    private RecordingSchedules $schedules;

    private MockPublisher $publisher;

    private Local $device;

    private Document $project;

    private Document $app;

    private Document $release;

    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/distribution-listings-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory, 0o700, true);

        $this->database = new ListingDatabase();
        $this->store = new ListingStore();
        $this->schedules = new RecordingSchedules();
        $this->publisher = new MockPublisher();
        $this->device = new Local($this->directory);
        $this->project = new Document(['$id' => 'project', '$sequence' => '7']);

        $this->app = $this->database->seed('distributionApps', ['$id' => 'app', 'name' => 'Example']);
        $this->database->seed('distributionConnections', ['$id' => 'connection', 'provider' => 'google', 'status' => 'active']);
        $this->database->seed('distributionTargets', [
            '$id' => 'android',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'platform' => 'android',
            'identifier' => self::PACKAGE,
            'connectionId' => 'connection',
        ]);
        $this->database->seed('distributionTargets', [
            '$id' => 'ios',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'platform' => 'ios',
            'identifier' => self::BUNDLE,
            'connectionId' => 'connection',
        ]);
        $this->database->seed('distributionArtifacts', [
            '$id' => 'android',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'platform' => 'android',
            'status' => 'ready',
            'identifier' => self::PACKAGE,
            'versionName' => '1.2.0',
            'versionCode' => 88,
        ]);
        $this->database->seed('distributionArtifacts', [
            '$id' => 'ios',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'platform' => 'ios',
            'status' => 'ready',
            'identifier' => self::BUNDLE,
            'versionName' => '2.0.0',
            'buildNumber' => '7',
        ]);
        $this->release = $this->releaseOf('release');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testPathIsUnderTheListingDirectoryOfTheDevice(): void
    {
        $this->assertSame($this->directory . '/listings/listing/shot.png', Listings::path($this->device, 'listing', 'shot', 'png'));
    }

    public function testSnapshotCopiesTheListingsOfThePlatformInCreationOrder(): void
    {
        $screenshot = $this->screenshot('second', 'shot', 'phoneScreenshots', ['aiGeneratedState' => 'aiGeneratedStateNotAiGenerated']);
        $this->listingOf('second', 'android', 'en-US', ['name' => 'Example'], [$screenshot]);
        $this->listingOf('third', 'android', 'de-DE', ['name' => 'Beispiel']);
        $this->listingOf('apple', 'ios', 'en-US', ['name' => 'Example']);
        $this->listingOf('other', 'android', 'fr-FR', ['name' => 'Autre'], appInternalId: 'other-app');

        $this->listings()->snapshot($this->release, Platform::Android, 'production');

        $this->assertSame(['android' => ['listings' => [
            ['$id' => 'second', 'locale' => 'en-US', 'revision' => 1, 'fields' => ['name' => 'Example'], 'screenshots' => [$screenshot]],
            ['$id' => 'third', 'locale' => 'de-DE', 'revision' => 1, 'fields' => ['name' => 'Beispiel'], 'screenshots' => []],
        ]]], $this->snapshot());
        $this->assertSame([Method::Equal, Method::Equal, Method::OrderAsc, Method::Limit], $this->methods($this->database->finds('distributionListings')[0]));
    }

    public function testSecondProductionSnapshotKeepsTheFirst(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'First']);
        $this->listings()->snapshot($this->release, Platform::Android, 'production');
        $this->database->updateDocument('distributionListings', 'listing', new Document(['fields' => ['name' => 'Second']]));

        $this->listings()->snapshot($this->release, Platform::Android, 'production');

        $this->assertSame(['name' => 'First'], $this->snapshot()['android']['listings'][0]['fields']);
        $this->assertCount(1, $this->database->writes('distributionReleases'));
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function unsnapshotted(): \Iterator
    {
        yield 'beta track' => ['beta', true];
        yield 'internal track' => ['internal', true];
        yield 'production without listings' => ['production', false];
    }

    #[DataProvider('unsnapshotted')]
    public function testSnapshotWritesNothing(string $track, bool $listed): void
    {
        if ($listed) {
            $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);
        }

        $this->listings()->snapshot($this->release, Platform::Android, $track);

        $this->assertSame([], $this->database->writes('distributionReleases'));
        $this->assertSame([], $this->snapshot());
    }

    public function testSnapshotOfASecondPlatformKeepsTheFirst(): void
    {
        $this->listingOf('android', 'android', 'en-US', ['name' => 'Example']);
        $this->listingOf('ios', 'ios', 'en-US', ['subtitle' => 'Example']);

        $this->listings()->snapshot($this->release, Platform::Android, 'production');
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');

        $snapshot = $this->snapshot();
        $this->assertSame(['android', 'ios'], \array_keys($snapshot));
        $this->assertSame('android', $snapshot['android']['listings'][0]['$id']);
        $this->assertSame('ios', $snapshot['ios']['listings'][0]['$id']);
    }

    public function testIosSnapshotInheritsTheLibraryOfTheLatestEarlierReleaseWithAssets(): void
    {
        $this->releaseOf('oldest', ['ios' => ['listings' => [], 'libraryId' => 'library-old', 'assets' => ['old' => 'image-old']]]);
        $this->releaseOf('earlier', ['ios' => ['listings' => [], 'libraryId' => 'library', 'assets' => ['digest' => 'image'], 'synced' => ['submission-old']]]);
        $this->releaseOf('foreign', ['ios' => ['listings' => [], 'libraryId' => 'library-foreign', 'assets' => ['foreign' => 'image-foreign']]], 'other-app');
        $this->releaseOf('android-only', ['android' => ['listings' => []]]);
        $this->releaseOf('empty', ['ios' => ['listings' => [], 'assets' => []]]);
        $current = $this->releaseOf('current');
        $this->releaseOf('newer', ['ios' => ['listings' => [], 'libraryId' => 'library-newer', 'assets' => ['newer' => 'image-newer']]]);
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);

        $this->listings()->snapshot($current, Platform::Ios, 'production');

        $ios = $this->snapshot('current')['ios'];
        $this->assertSame('library', $ios['libraryId']);
        $this->assertSame(['digest' => 'image'], $ios['assets']);
        $this->assertArrayNotHasKey('synced', $ios, 'synced submissions belong to their own release');
        $queries = $this->database->finds('distributionReleases')[0];
        $this->assertSame([Method::Select, Method::Equal, Method::OrderDesc, Method::CursorAfter, Method::Limit], $this->methods($queries));
        $this->assertSame('current', $queries[3]->getValue()->getId());
    }

    public function testIosSnapshotPagesThroughEarlierReleases(): void
    {
        $this->releaseOf('library', ['ios' => ['listings' => [], 'libraryId' => 'library', 'assets' => ['digest' => 'image']]]);

        for ($index = 0; $index < Listings::PAGE + 5; $index++) {
            $this->releaseOf("empty-{$index}");
        }

        $current = $this->releaseOf('current');
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);

        $this->listings()->snapshot($current, Platform::Ios, 'production');

        $this->assertSame('library', $this->snapshot('current')['ios']['libraryId']);
        $finds = $this->database->finds('distributionReleases');
        $this->assertCount(2, $finds);
        $this->assertSame('empty-5', $finds[1][3]->getValue()->getId());
    }

    public function testAndroidSnapshotInheritsNothing(): void
    {
        $this->releaseOf('earlier', ['ios' => ['listings' => [], 'libraryId' => 'library', 'assets' => ['digest' => 'image']]]);
        $current = $this->releaseOf('current');
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);

        $this->listings()->snapshot($current, Platform::Android, 'production');

        $this->assertSame(['android'], \array_keys($this->snapshot('current')));
        $this->assertSame([], $this->database->finds('distributionReleases'));
    }

    public function testOversizedSnapshotIsRefused(): void
    {
        for ($index = 0; $index < 20; $index++) {
            $this->listingOf("listing-{$index}", 'android', "l{$index}", ['description' => \str_repeat('a', 60000)]);
        }

        $error = $this->caught(fn () => $this->listings()->snapshot($this->release, Platform::Android, 'production'));

        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $error->getType());
        $this->assertStringContainsString('The android store listings are too large to snapshot into the release (', $error->getMessage());
        $this->assertStringEndsWith('characters, at most 1000000). Shorten the listings or remove locales.', $error->getMessage());
        $this->assertSame([], $this->database->writes('distributionReleases'));
    }

    public function testSnapshotRetriesAConcurrentReleaseWrite(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);
        $concurrent = ['ios' => ['listings' => [], 'libraryId' => 'library']];
        $this->database->interfere('distributionReleases', 'release', ['listing' => $concurrent]);

        $this->listings()->snapshot($this->release, Platform::Android, 'production');

        $snapshot = $this->snapshot();
        $this->assertSame($concurrent['ios'], $snapshot['ios']);
        $this->assertSame('listing', $snapshot['android']['listings'][0]['$id']);
    }

    public function testSnapshotGivesUpAfterThreeConcurrentReleaseWrites(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->database->interfere('distributionReleases', 'release', ['listing' => ['ios' => ['listings' => [], 'libraryId' => "library-{$attempt}"]]]);
        }

        $error = $this->caught(fn () => $this->listings()->snapshot($this->release, Platform::Android, 'production'));

        $this->assertSame(Exception::GENERAL_SERVER_ERROR, $error->getType());
        $this->assertArrayNotHasKey('android', $this->snapshot());
    }

    public function testCreateSnapshotsTheProductionListingBeforeTheSubmission(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);

        $submission = $this->submissions()->create($this->app, $this->release, $this->target('android'), $this->artifact('android'), 'production', true, 'key-1');

        $this->assertInstanceOf(Document::class, $submission);
        $this->assertSame('listing', $this->snapshot()['android']['listings'][0]['$id']);
        $collections = \array_column($this->database->writes(), 'collection');
        $this->assertLessThan(\array_search('distributionSubmissions', $collections, true), \array_search('distributionReleases', $collections, true));
    }

    public function testOversizedSnapshotCreatesNoSubmission(): void
    {
        for ($index = 0; $index < 20; $index++) {
            $this->listingOf("listing-{$index}", 'android', "l{$index}", ['description' => \str_repeat('a', 60000)]);
        }

        $error = $this->caught(fn () => $this->submissions()->create($this->app, $this->release, $this->target('android'), $this->artifact('android'), 'production', true, 'key-1'));

        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $error->getType());
        $this->assertSame([], $this->database->writes('distributionSubmissions'));
        $this->assertSame([], $this->schedules->calls->getArrayCopy());
        $this->assertNull($this->publisher->getEvents(self::QUEUE));
    }

    public function testDuplicateCreateLeavesTheSnapshot(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'First']);
        $submissions = $this->submissions();
        $submissions->create($this->app, $this->release, $this->target('android'), $this->artifact('android'), 'production', true, 'key-1');
        $this->database->updateDocument('distributionListings', 'listing', new Document(['fields' => ['name' => 'Second']]));

        $replayed = $submissions->create($this->app, $this->release, $this->target('android'), $this->artifact('android'), 'production', true, 'key-1');

        $this->assertNull($replayed);
        $this->assertSame(['name' => 'First'], $this->snapshot()['android']['listings'][0]['fields']);
    }

    public function testGoogleWritesTheListingIntoTheUploadEditBeforeReleasing(): void
    {
        $screenshot = $this->screenshot('listing', 'shot', 'phoneScreenshots', ['aiGeneratedState' => 'aiGeneratedStateNotAiGenerated']);
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example', 'shortDescription' => 'Short'], [$screenshot]);
        $this->listings()->snapshot($this->release, Platform::Android, 'production');
        $submission = $this->submission('android');

        $released = $this->release($submission, 'android');

        $this->assertSame(['listing', 'release'], $this->store->calls);
        $listing = $this->store->listings[0]['listing'];
        $this->assertSame(self::PACKAGE, $listing->identifier);
        $this->assertSame('en-US', $listing->locale);
        $this->assertSame(['name' => 'Example', 'shortDescription' => 'Short'], $listing->fields);
        $this->assertSame([[
            'set' => 'phoneScreenshots',
            'path' => $screenshot['path'],
            'size' => $screenshot['size'],
            'width' => 1080,
            'height' => 1920,
            'digest' => $screenshot['digest'],
        ]], $listing->screenshots);
        $this->assertSame($this->device, $listing->device);
        $this->assertSame(self::EDIT, $this->store->listings[0]['external']['editId']);
        $this->assertSame('released', $released->getAttribute('step'));
        $this->assertSame(['complete' => true, 'polls' => 0], $released->getAttribute('external')['listing']);
        $this->assertTrue($released->getAttribute('external')['committed']);
        $this->assertTrue(Listings::isSynced($released));
        $this->assertFalse(Listings::isSynced($submission));
    }

    public function testGoogleWritesEveryLocaleIntoTheSameEdit(): void
    {
        $this->listingOf('english', 'android', 'en-US', ['name' => 'Example']);
        $this->listingOf('german', 'android', 'de-DE', ['name' => 'Beispiel']);
        $this->listings()->snapshot($this->release, Platform::Android, 'production');

        $this->release($this->submission('android'), 'android');

        $this->assertSame(['listing', 'listing', 'release'], $this->store->calls);
        $this->assertSame(['en-US', 'de-DE'], \array_map(static fn (array $call): string => $call['listing']->locale, $this->store->listings));
        $this->assertSame([self::EDIT, self::EDIT], \array_map(static fn (array $call): string => $call['external']['editId'], $this->store->listings));
    }

    public function testGoogleRewritesAListingAlreadySyncedIntoAnEarlierEdit(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Android, 'production');
        $submission = $this->submission('android', ['external' => [...$this->uploaded(), 'listing' => ['complete' => true, 'polls' => 0]]]);

        $this->release($submission, 'android');

        $this->assertSame(['listing', 'release'], $this->store->calls);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function closedEdits(): \Iterator
    {
        yield 'no edit' => [['packageName' => self::PACKAGE]];
        yield 'empty edit' => [['packageName' => self::PACKAGE, 'editId' => '']];
        yield 'committed edit' => [['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'committed' => true]];
        yield 'edit of another package' => [['packageName' => 'io.example.other', 'editId' => self::EDIT]];
        yield 'edit without a package' => [['editId' => self::EDIT]];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('closedEdits')]
    public function testGoogleRefusesToSyncWithoutAnOpenEdit(array $external): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Android, 'production');

        $error = $this->caught(fn () => $this->sync($this->submission('android', ['external' => $external]), 'android', $external));

        $this->assertSame(Exception::GENERAL_SERVER_ERROR, $error->getType());
        $this->assertSame('No open Google Play edit to write the listing into.', $error->getMessage());
        $this->assertSame([], $this->store->calls);
    }

    public function testGoogleRefusesAListingCommittedOutsideTheUploadEdit(): void
    {
        $this->listingOf('listing', 'android', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Android, 'production');
        $this->store->results = [['packageName' => self::PACKAGE, 'committed' => true]];

        $error = $this->caught(fn () => $this->sync($this->submission('android'), 'android', $this->uploaded()));

        $this->assertSame(Exception::GENERAL_SERVER_ERROR, $error->getType());
        $this->assertSame(['listing'], $this->store->calls);
    }

    public function testAppleListingStillProcessingWaitsBeforeReleasing(): void
    {
        $screenshot = $this->screenshot('listing', 'shot', 'APP_IPHONE_67');
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example'], [$screenshot]);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $this->store->results = [['appId' => 'apple-app', 'version' => '2.0.0', 'libraryId' => 'library', 'assets' => [$screenshot['digest'] => 'image'], 'complete' => false]];
        $submission = $this->submission('ios');

        $waiting = $this->release($submission, 'ios');

        $this->assertSame(['listing'], $this->store->calls);
        $this->assertSame(['appId' => 'apple-app', 'version' => '2.0.0'], $this->store->listings[0]['external']);
        $this->assertSame('processed', $waiting->getAttribute('step'));
        $this->assertSame(['complete' => false, 'polls' => 1], $waiting->getAttribute('external')['listing']);
        $this->assertEqualsWithDelta(\time() + (int) Submissions::backoff(1), (new \DateTime($waiting->getAttribute('retryAt')))->getTimestamp(), 2);
        $ios = $this->snapshot()['ios'];
        $this->assertSame('library', $ios['libraryId']);
        $this->assertSame([$screenshot['digest'] => 'image'], $ios['assets']);
        $this->assertArrayNotHasKey('synced', $ios);
        $this->assertFalse(Listings::isSynced($waiting));
    }

    public function testAppleListingReleasesOnceProcessed(): void
    {
        $screenshot = $this->screenshot('listing', 'shot', 'APP_IPHONE_67');
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example'], [$screenshot]);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $this->store->results = [
            ['libraryId' => 'library', 'assets' => [$screenshot['digest'] => 'image'], 'complete' => false],
            ['libraryId' => 'library', 'assets' => [$screenshot['digest'] => 'image'], 'complete' => true],
        ];
        $submission = $this->submission('ios');

        $waiting = $this->release($submission, 'ios');
        $released = $this->release($waiting, 'ios');

        $this->assertSame(['listing', 'listing', 'release'], $this->store->calls);
        $this->assertSame(['appId' => 'apple-app', 'version' => '2.0.0', 'libraryId' => 'library', 'assets' => [$screenshot['digest'] => 'image']], $this->store->listings[1]['external']);
        $this->assertSame('released', $released->getAttribute('step'));
        $this->assertSame(['complete' => true, 'polls' => 1], $released->getAttribute('external')['listing']);
        $this->assertSame(['submission'], $this->snapshot()['ios']['synced']);
        $this->assertFalse(Listings::isSynced($waiting));
        $this->assertTrue(Listings::isSynced($released));
    }

    public function testAppleListingTargetsTheReleaseVersionWithoutAnArtifactVersion(): void
    {
        $this->database->updateDocument('distributionArtifacts', 'ios', new Document(['versionName' => '']));
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $this->store->results = [['complete' => true]];

        $this->release($this->submission('ios'), 'ios');

        $this->assertSame('1.2.0', $this->store->listings[0]['external']['version']);
        $this->assertSame('1.2.0', $this->store->release?->version);
    }

    /**
     * Covers the domain path only: the worker retries through fail() exactly as below.
     */
    public function testAppleRetryAfterAFailedReleaseDoesNotRepeatTheListing(): void
    {
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $this->store->results = [['complete' => true]];
        $this->store->failures['release'] = new Unavailable('App Store Connect is down');
        $submission = $this->submission('ios');
        $submissions = $this->submissions();

        try {
            $this->release($submission, 'ios', $submissions);
            $this->fail('The release step should have thrown');
        } catch (Unavailable $error) {
            $failed = $submissions->fail($this->stored('distributionSubmissions', 'submission'), $error);
        }

        $this->assertSame(1, $failed->getAttribute('attempt'));
        $this->assertArrayNotHasKey('listing', $failed->getAttribute('external'));
        $this->assertSame(['submission'], $this->snapshot()['ios']['synced']);
        $this->assertFalse(Listings::isSynced($failed));

        $this->store->calls = [];
        $this->store->failures = [];

        $released = $this->release($failed, 'ios', $submissions);

        $this->assertSame(['release'], $this->store->calls);
        $this->assertSame('released', $released->getAttribute('step'));
        $this->assertSame(['complete' => true, 'polls' => 0], $released->getAttribute('external')['listing']);
        $this->assertTrue(Listings::isSynced($released));
    }

    public function testAppleListingProcessingGivesUpAfterADay(): void
    {
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $last = 1;

        while (Submissions::backoff($last + 1) !== null) {
            $last++;
        }

        $this->store->results = [['complete' => false], ['complete' => false]];
        $submission = $this->submission('ios');

        $external = $this->sync($submission, 'ios', ['appId' => 'apple-app', 'listing' => ['complete' => false, 'polls' => $last - 1]]);
        $this->assertSame(['complete' => false, 'polls' => $last], $external['listing']);

        $error = $this->caught(fn () => $this->sync($submission, 'ios', ['appId' => 'apple-app', 'listing' => ['complete' => false, 'polls' => $last]]));
        $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $error->getType());
        $this->assertSame('App Store Connect did not finish processing the listing screenshots within a day.', $error->getMessage());
    }

    public function testAppleSyncRetriesAConcurrentReleaseWrite(): void
    {
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $stored = $this->snapshot();
        $this->database->interfere('distributionReleases', 'release', ['listing' => [...$stored, 'ios' => [...$stored['ios'], 'synced' => ['other']]]]);
        $this->store->results = [['libraryId' => 'library', 'complete' => true]];

        $this->sync($this->submission('ios'), 'ios', ['appId' => 'apple-app']);

        $ios = $this->snapshot()['ios'];
        $this->assertSame(['other', 'submission'], $ios['synced']);
        $this->assertSame('library', $ios['libraryId']);
    }

    public function testAppleSyncRetriesTheStepAfterThreeConcurrentReleaseWrites(): void
    {
        $this->listingOf('listing', 'ios', 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::Ios, 'production');
        $this->store->results = [['libraryId' => 'library', 'complete' => true]];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->database->interfere('distributionReleases', 'release', ['notes' => ["note-{$attempt}"]]);
        }

        $submissions = $this->submissions();

        try {
            $this->release($this->submission('ios'), 'ios', $submissions);
            $this->fail('The release step should have thrown');
        } catch (Unavailable $error) {
            $this->assertSame('The release listing changed concurrently 3 times in a row.', $error->getMessage());
            $failed = $submissions->fail($this->stored('distributionSubmissions', 'submission'), $error);
        }

        $this->assertSame(['listing'], $this->store->calls);
        $this->assertSame('processing', $failed->getAttribute('status'));
        $this->assertSame(1, $failed->getAttribute('attempt'));
        $this->assertNotEmpty($failed->getAttribute('retryAt'));
        $this->assertArrayNotHasKey('synced', $this->snapshot()['ios']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function platforms(): \Iterator
    {
        yield 'android' => ['android'];
        yield 'ios' => ['ios'];
    }

    #[DataProvider('platforms')]
    public function testMissingScreenshotFailsTheCheck(string $platform): void
    {
        $screenshot = $this->screenshot('listing', 'shot', 'phoneScreenshots');
        $this->listingOf('listing', $platform, 'en-US', ['name' => 'Example'], [$screenshot]);
        $this->listings()->snapshot($this->release, Platform::from($platform), 'production');
        \unlink($screenshot['path']);

        $error = $this->caught(fn () => $this->sync($this->submission($platform), $platform, $this->uploaded()));

        $this->assertSame(Exception::DISTRIBUTION_CHECK_FAILED, $error->getType());
        $this->assertSame('Screenshot phoneScreenshots of the en-US listing is missing from storage. Create a new release to submit the current listing.', $error->getMessage());
        $this->assertSame([], $this->store->calls);
    }

    #[DataProvider('platforms')]
    public function testNonProductionSubmissionIsNotSynced(string $platform): void
    {
        $this->listingOf('listing', $platform, 'en-US', ['name' => 'Example']);
        $this->listings()->snapshot($this->release, Platform::from($platform), 'production');
        $writes = \count($this->database->writes());
        $track = $platform === 'ios' ? 'testflight' : 'beta';

        $external = $this->sync($this->submission($platform, ['track' => $track]), $platform, $this->uploaded());

        $this->assertSame($this->uploaded(), $external);
        $this->assertSame([], $this->store->calls);
        $this->assertCount($writes, $this->database->writes());
    }

    #[DataProvider('platforms')]
    public function testProductionSubmissionWithoutASnapshotIsNotSynced(string $platform): void
    {
        $external = $this->sync($this->submission($platform), $platform, $this->uploaded());

        $this->assertSame($this->uploaded(), $external);
        $this->assertSame([], $this->store->calls);
    }

    /**
     * @return \Iterator<string, array{Platform, array<array-key, mixed>|string, array<string, string>}>
     */
    public static function validFields(): \Iterator
    {
        yield 'empty' => [Platform::Android, [], []];
        yield 'empty json object' => [Platform::Ios, '{}', []];
        yield 'android fields' => [Platform::Android, ['name' => 'Example', 'shortDescription' => 'Short', 'description' => 'Long'], ['name' => 'Example', 'shortDescription' => 'Short', 'description' => 'Long']];
        yield 'ios fields' => [Platform::Ios, ['subtitle' => 'Sub', 'keywords' => 'a,b', 'privacyUrl' => 'https://example.com/privacy'], ['subtitle' => 'Sub', 'keywords' => 'a,b', 'privacyUrl' => 'https://example.com/privacy']];
        yield 'json string' => [Platform::Android, '{"name":"Example"}', ['name' => 'Example']];
        yield 'empty text' => [Platform::Android, ['name' => ''], ['name' => '']];
    }

    /**
     * @param array<array-key, mixed>|string $fields
     * @param array<string, string> $expected
     */
    #[DataProvider('validFields')]
    public function testFieldsAcceptsThePlatformFields(Platform $platform, array|string $fields, array $expected): void
    {
        $this->assertSame($expected, Listings::fields($platform, $fields));
    }

    /**
     * @return \Iterator<string, array{Platform, array<array-key, mixed>|string, string}>
     */
    public static function invalidFields(): \Iterator
    {
        yield 'unknown field' => [Platform::Android, ['title' => 'Example'], 'Listing field "title" is not supported on android. Allowed fields: name, shortDescription, description.'];
        yield 'ios field on android' => [Platform::Android, ['subtitle' => 'Example'], 'Listing field "subtitle" is not supported on android. Allowed fields: name, shortDescription, description.'];
        yield 'android field on ios' => [Platform::Ios, ['shortDescription' => 'Example'], 'Listing field "shortDescription" is not supported on ios. Allowed fields: name, subtitle, description, keywords, promotionalText, supportUrl, marketingUrl, privacyUrl.'];
        yield 'number' => [Platform::Android, ['name' => 1], 'Listing field "name" must be a string.'];
        yield 'nested' => [Platform::Ios, ['name' => ['en' => 'Example']], 'Listing field "name" must be a string.'];
        yield 'list' => [Platform::Android, ['Example'], 'The listing fields must be a JSON object.'];
        yield 'json list' => [Platform::Android, '["Example"]', 'The listing fields must be a JSON object.'];
        yield 'json scalar' => [Platform::Android, '"Example"', 'The listing fields must be a JSON object.'];
        yield 'invalid json' => [Platform::Android, '{"name":', 'The listing fields must be a JSON object.'];
        yield 'oversized' => [Platform::Android, ['description' => \str_repeat('a', Listings::LENGTH)], 'The listing fields are too long (' . (Listings::LENGTH + 18) . ' characters, at most ' . Listings::LENGTH . ').'];
    }

    /**
     * @param array<array-key, mixed>|string $fields
     */
    #[DataProvider('invalidFields')]
    public function testFieldsRejectsInvalidFields(Platform $platform, array|string $fields, string $message): void
    {
        $error = $this->caught(static fn (): array => Listings::fields($platform, $fields));

        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $error->getType());
        $this->assertSame($message, $error->getMessage());
    }

    public function testFieldsMatchThePackageListingFields(): void
    {
        foreach (Listings::FIELDS as $fields) {
            new Listing('io.example.app', 'en-US', \array_fill_keys($fields, 'text'));
        }

        $this->assertEqualsCanonicalizing(Platform::values(), \array_keys(Listings::FIELDS));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, bool, bool, int}>
     */
    public static function externals(): \Iterator
    {
        yield 'never synced' => [[], false, false, 0];
        yield 'processing' => [['listing' => ['complete' => false, 'polls' => 3]], true, false, 3];
        yield 'complete' => [['listing' => ['complete' => true, 'polls' => 2]], false, true, 2];
        yield 'no completion recorded' => [['listing' => []], true, false, 0];
        yield 'malformed' => [['listing' => 'complete'], false, false, 0];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('externals')]
    public function testListingStateIsReadFromExternal(array $external, bool $pending, bool $synced, int $polls): void
    {
        $this->assertSame($pending, Listings::isPending($external));
        $this->assertSame($synced, Listings::isSynced(new Document(['external' => $external])));
        $this->assertSame($polls, Listings::polls($external));
    }

    private function listings(): Listings
    {
        return new Listings($this->database, $this->device);
    }

    private function submissions(): Submissions
    {
        return new Submissions(
            $this->database,
            $this->project,
            new ScriptedStores($this->store),
            $this->schedules,
            $this->device,
            new DistributionSubmissionPublisher($this->publisher, new Queue(self::QUEUE)),
        );
    }

    private function release(Document $submission, string $platform, ?Submissions $submissions = null): Document
    {
        return ($submissions ?? $this->submissions())->release(
            $submission,
            $this->stored('distributionReleases', 'release'),
            $this->target($platform),
            $this->artifact($platform),
            $this->stored('distributionConnections', 'connection'),
        );
    }

    /**
     * @param array<string, mixed> $external
     * @return array<string, mixed>
     */
    private function sync(Document $submission, string $platform, array $external): array
    {
        return $this->listings()->sync($this->store, $submission, $this->release, $this->target($platform), $this->artifact($platform), $external);
    }

    /**
     * @return array<string, mixed>
     */
    private function uploaded(): array
    {
        return ['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'versionCode' => 88];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function submission(string $platform, array $attributes = []): Document
    {
        return $this->database->seed('distributionSubmissions', [
            '$id' => 'submission',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'releaseId' => 'release',
            'releaseInternalId' => $this->release->getSequence(),
            'targetId' => $platform,
            'targetInternalId' => $this->target($platform)->getSequence(),
            'artifactId' => $platform,
            'artifactInternalId' => $this->artifact($platform)->getSequence(),
            'platform' => $platform,
            'provider' => $platform === 'ios' ? 'apple' : 'google',
            'track' => 'production',
            'rollout' => 1.0,
            'automatic' => true,
            'status' => 'processing',
            'step' => 'processed',
            'external' => $platform === 'ios' ? ['appId' => 'apple-app', 'buildId' => 'build'] : $this->uploaded(),
            'key' => 'submission',
            'attempt' => 0,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $listing
     */
    private function releaseOf(string $id, array $listing = [], ?string $appInternalId = null): Document
    {
        return $this->database->seed('distributionReleases', [
            '$id' => $id,
            'appId' => 'app',
            'appInternalId' => $appInternalId ?? $this->app->getSequence(),
            'version' => '1.2.0',
            'notes' => [],
            'status' => 'draft',
            'listing' => $listing,
        ]);
    }

    /**
     * @param array<string, string> $fields
     * @param list<array<string, mixed>> $screenshots
     */
    private function listingOf(string $id, string $platform, string $locale, array $fields, array $screenshots = [], ?string $appInternalId = null): void
    {
        $this->database->seed('distributionListings', [
            '$id' => $id,
            'appId' => 'app',
            'appInternalId' => $appInternalId ?? $this->app->getSequence(),
            'platform' => $platform,
            'locale' => $locale,
            'fields' => $fields,
            'screenshots' => $screenshots,
            'revision' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function screenshot(string $listingId, string $screenshotId, string $set, array $extra = []): array
    {
        $path = Listings::path($this->device, $listingId, $screenshotId, 'png');
        $content = \random_bytes(512);

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o700, true);
        }

        \file_put_contents($path, $content);

        return [
            '$id' => $screenshotId,
            'set' => $set,
            'path' => $path,
            'size' => \strlen($content),
            'width' => 1080,
            'height' => 1920,
            'digest' => \hash('sha256', $content),
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $releaseId = 'release'): array
    {
        $listing = $this->stored('distributionReleases', $releaseId)->getAttribute('listing', []);

        return \is_array($listing) ? $listing : [];
    }

    private function target(string $platform): Document
    {
        return $this->stored('distributionTargets', $platform);
    }

    private function artifact(string $platform): Document
    {
        return $this->stored('distributionArtifacts', $platform);
    }

    private function stored(string $collection, string $id): Document
    {
        return $this->database->getDocument($collection, $id);
    }

    /**
     * @param list<Query> $queries
     * @return list<Method>
     */
    private function methods(array $queries): array
    {
        return \array_map(static fn (Query $query): Method => $query->getMethod(), $queries);
    }

    private function caught(callable $callback): Exception
    {
        try {
            $callback();
        } catch (Exception $error) {
            return $error;
        }

        $this->fail('Expected an ' . Exception::class);
    }

    private function remove(string $path): void
    {
        foreach (\glob($path . '/*') ?: [] as $child) {
            \is_dir($child) ? $this->remove($child) : \unlink($child);
        }

        \rmdir($path);
    }
}
