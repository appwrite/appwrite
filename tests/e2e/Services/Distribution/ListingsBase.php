<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use CURLFile;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;

trait ListingsBase
{
    private const int CHUNK = 5 * 1024 * 1024;

    private const int IMAGE_LIMIT = 15728640;

    private const string NOT_AI_GENERATED = 'aiGeneratedStateNotAiGenerated';

    public function testCreateListing(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $fields = ['name' => 'Appwrite', 'shortDescription' => 'Build fast.', 'description' => 'Build like a team of hundreds.'];

        /**
         * Test for SUCCESS
         */
        $created = $this->createListing($appId, ['fields' => $fields]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertNotEmpty($created['body']['$id']);
        $this->assertNotEmpty($created['body']['$createdAt']);
        $this->assertNotEmpty($created['body']['$updatedAt']);
        $this->assertSame($appId, $created['body']['appId']);
        $this->assertSame('android', $created['body']['platform']);
        $this->assertSame('en-US', $created['body']['locale']);
        $this->assertSame($fields, $created['body']['fields']);
        $this->assertSame([], $created['body']['screenshots']);
        $this->assertSame(1, $created['body']['revision']);

        $ios = $this->createListing($appId, [
            'platform' => 'ios',
            'fields' => ['name' => 'Appwrite', 'subtitle' => 'Backend', 'keywords' => 'backend,cloud', 'privacyUrl' => 'https://appwrite.io/privacy'],
        ]);
        $this->assertSame(201, $ios['headers']['status-code']);
        $this->assertSame('ios', $ios['body']['platform']);
        $this->assertSame('Backend', $ios['body']['fields']['subtitle']);

        $bare = $this->createListing($appId, ['locale' => 'de-DE', 'fields' => null]);
        $this->assertSame(201, $bare['headers']['status-code']);
        $this->assertSame([], $bare['body']['fields']);

        $fetched = $this->getListing($appId, $created['body']['$id']);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($fields, $fetched['body']['fields']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->createListing($appId, ['locale' => 'fr-FR', 'fields' => ['title' => 'Appwrite']]);
        $this->assertSame(400, $unknown['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unknown['body']['type']);

        $iosOnly = $this->createListing($appId, ['locale' => 'fr-FR', 'fields' => ['subtitle' => 'Backend']]);
        $this->assertSame(400, $iosOnly['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $iosOnly['body']['type']);

        $number = $this->createListing($appId, ['locale' => 'fr-FR', 'fields' => ['name' => 5]]);
        $this->assertSame(400, $number['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $number['body']['type']);

        $duplicate = $this->createListing($appId);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('resource_already_exists', $duplicate['body']['type']);

        $duplicateId = $this->createListing($appId, ['listingId' => $created['body']['$id'], 'locale' => 'fr-FR']);
        $this->assertSame(409, $duplicateId['headers']['status-code']);
        $this->assertSame('resource_already_exists', $duplicateId['body']['type']);

        $missing = $this->createListing(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);

        $listed = $this->listListings($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(3, $listed['body']['total']);
    }

    public function testListListings(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $english = $this->createListing($appId);
        $german = $this->createListing($appId, ['locale' => 'de-DE']);
        $ios = $this->createListing($appId, ['platform' => 'ios']);
        $this->assertSame(201, $english['headers']['status-code']);
        $this->assertSame(201, $german['headers']['status-code']);
        $this->assertSame(201, $ios['headers']['status-code']);

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);
        $foreign = $this->createListing($other['body']['$id']);
        $this->assertSame(201, $foreign['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $listed = $this->listListings($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(3, $listed['body']['total']);
        $this->assertEqualsCanonicalizing(
            [$english['body']['$id'], $german['body']['$id'], $ios['body']['$id']],
            \array_column($listed['body']['listings'], '$id'),
        );

        $android = $this->listListings($appId, [Query::equal('platform', ['android'])->toString()]);
        $this->assertSame(200, $android['headers']['status-code']);
        $this->assertSame(2, $android['body']['total']);
        $this->assertEqualsCanonicalizing(
            [$english['body']['$id'], $german['body']['$id']],
            \array_column($android['body']['listings'], '$id'),
        );

        $localized = $this->listListings($appId, [Query::equal('locale', ['de-DE'])->toString()]);
        $this->assertSame(200, $localized['headers']['status-code']);
        $this->assertSame(1, $localized['body']['total']);
        $this->assertSame([$german['body']['$id']], \array_column($localized['body']['listings'], '$id'));

        $paged = $this->listListings($appId, [
            Query::orderAsc('$sequence')->toString(),
            Query::cursorAfter(new Document(['$id' => $english['body']['$id']]))->toString(),
        ]);
        $this->assertSame(200, $paged['headers']['status-code']);
        $this->assertSame([$german['body']['$id'], $ios['body']['$id']], \array_column($paged['body']['listings'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/listings', $this->headers(), [
            'total' => false,
        ]);
        $this->assertSame(200, $untotaled['headers']['status-code']);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(3, $untotaled['body']['listings']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listListings($appId, [Query::equal('fields', ['x'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $cursor = $this->listListings($appId, [Query::cursorAfter(new Document(['$id' => $foreign['body']['$id']]))->toString()]);
        $this->assertSame(400, $cursor['headers']['status-code']);
        $this->assertSame('general_cursor_not_found', $cursor['body']['type']);

        $missing = $this->listListings(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetListing(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createListing($appId, ['fields' => ['name' => 'Appwrite']]);
        $this->assertSame(201, $created['headers']['status-code']);
        $listingId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $listing = $this->getListing($appId, $listingId);
        $this->assertSame(200, $listing['headers']['status-code']);
        $this->assertSame($listingId, $listing['body']['$id']);
        $this->assertSame($appId, $listing['body']['appId']);
        $this->assertSame(['name' => 'Appwrite'], $listing['body']['fields']);
        $this->assertSame(1, $listing['body']['revision']);

        /**
         * Test for FAILURE
         */
        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);
        $foreign = $this->getListing($other['body']['$id'], $listingId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $foreign['body']['type']);

        $missing = $this->getListing($appId, ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $missing['body']['type']);

        $noApp = $this->getListing(ID::unique(), $listingId);
        $this->assertSame(404, $noApp['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $noApp['body']['type']);
    }

    public function testUpdateListing(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createListing($appId, ['fields' => ['name' => 'Appwrite', 'shortDescription' => 'Build fast.']]);
        $this->assertSame(201, $created['headers']['status-code']);
        $listingId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $updated = $this->updateListing($appId, $listingId, ['description' => 'Build like a team of hundreds.']);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(['description' => 'Build like a team of hundreds.'], $updated['body']['fields']);
        $this->assertSame(2, $updated['body']['revision']);
        $this->assertSame('android', $updated['body']['platform']);
        $this->assertSame('en-US', $updated['body']['locale']);

        $fetched = $this->getListing($appId, $listingId);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame(['description' => 'Build like a team of hundreds.'], $fetched['body']['fields']);
        $this->assertSame(2, $fetched['body']['revision']);

        /**
         * Test for FAILURE
         */
        $iosOnly = $this->updateListing($appId, $listingId, ['subtitle' => 'Backend']);
        $this->assertSame(400, $iosOnly['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $iosOnly['body']['type']);
        $this->assertSame(2, $this->getListing($appId, $listingId)['body']['revision']);

        $missing = $this->updateListing($appId, ID::unique(), ['name' => 'Appwrite']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $missing['body']['type']);
    }

    public function testCreateListingScreenshot(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $listing = $this->createListing($appId);
        $this->assertSame(201, $listing['headers']['status-code']);
        $listingId = $listing['body']['$id'];
        $content = $this->image(1080, 1920, false);
        $screenshotId = ID::unique();

        /**
         * Test for SUCCESS
         */
        $created = $this->createListingScreenshot($appId, $listingId, $screenshotId, $content);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame($listingId, $created['body']['$id']);
        $this->assertSame(2, $created['body']['revision']);
        $this->assertCount(1, $created['body']['screenshots']);
        $screenshot = $created['body']['screenshots'][0];
        $this->assertSame($screenshotId, $screenshot['$id']);
        $this->assertSame('phoneScreenshots', $screenshot['set']);
        $this->assertSame(1080, $screenshot['width']);
        $this->assertSame(1920, $screenshot['height']);
        $this->assertSame(\strlen($content), $screenshot['size']);
        $this->assertSame(\hash('sha256', $content), $screenshot['digest']);
        $this->assertSame(self::NOT_AI_GENERATED, $screenshot['aiGeneratedState']);
        $this->assertArrayNotHasKey('path', $screenshot);

        $fetched = $this->getListing($appId, $listingId);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($created['body']['screenshots'], $fetched['body']['screenshots']);
        $this->assertSame(2, $fetched['body']['revision']);

        $generated = $this->createListingScreenshot($appId, $listingId, 'unique()', $this->image(320, 640, false));
        $this->assertSame(201, $generated['headers']['status-code']);
        $this->assertCount(2, $generated['body']['screenshots']);
        $this->assertNotSame('unique()', $generated['body']['screenshots'][1]['$id']);
        $this->assertSame(3, $generated['body']['revision']);

        $icon = $this->createListingScreenshot($appId, $listingId, ID::unique(), $this->image(512, 512, false), ['set' => 'icon']);
        $this->assertSame(201, $icon['headers']['status-code']);
        $this->assertSame('icon', $icon['body']['screenshots'][2]['set']);

        $first = $this->image(1500, 1500, true);
        $second = $this->image(1500, 1500, true);
        $this->assertGreaterThan(self::CHUNK, \strlen($first));
        $this->assertGreaterThan(self::CHUNK, \strlen($second));
        $this->assertLessThan(self::IMAGE_LIMIT, \strlen($first));
        $this->assertLessThan(self::IMAGE_LIMIT, \strlen($second));
        $firstId = ID::unique();
        $secondId = ID::unique();

        $firstHead = $this->createListingScreenshot($appId, $listingId, $firstId, \substr($first, 0, self::CHUNK), range: $this->range(0, self::CHUNK, \strlen($first)));
        $this->assertSame(202, $firstHead['headers']['status-code']);
        $this->assertSame($listingId, $firstHead['body']['$id']);
        $this->assertCount(3, $firstHead['body']['screenshots']);

        $secondHead = $this->createListingScreenshot($appId, $listingId, $secondId, \substr($second, 0, self::CHUNK), range: $this->range(0, self::CHUNK, \strlen($second)));
        $this->assertSame(202, $secondHead['headers']['status-code']);
        $this->assertCount(3, $secondHead['body']['screenshots']);

        $firstTail = $this->createListingScreenshot($appId, $listingId, $firstId, \substr($first, self::CHUNK), range: $this->range(self::CHUNK, \strlen($first), \strlen($first)), uploadId: $listingId);
        $this->assertSame(201, $firstTail['headers']['status-code']);
        $this->assertCount(4, $firstTail['body']['screenshots']);

        $secondTail = $this->createListingScreenshot($appId, $listingId, $secondId, \substr($second, self::CHUNK), range: $this->range(self::CHUNK, \strlen($second), \strlen($second)), uploadId: $listingId);
        $this->assertSame(201, $secondTail['headers']['status-code']);
        $this->assertCount(5, $secondTail['body']['screenshots']);
        $this->assertSame(6, $secondTail['body']['revision']);

        $chunked = \array_slice($secondTail['body']['screenshots'], 3);
        $this->assertSame([$firstId, $secondId], \array_column($chunked, '$id'));
        $this->assertSame([\hash('sha256', $first), \hash('sha256', $second)], \array_column($chunked, 'digest'));
        $this->assertSame([\strlen($first), \strlen($second)], \array_column($chunked, 'size'));
        $this->assertSame([1500, 1500], \array_column($chunked, 'width'));
        $this->assertSame([1500, 1500], \array_column($chunked, 'height'));

        /**
         * Test for FAILURE
         */
        $small = $this->image(320, 640, false);

        $unattested = $this->createListingScreenshot($appId, $listingId, ID::unique(), $small, ['aiGeneratedState' => null]);
        $this->assertSame(400, $unattested['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unattested['body']['type']);

        $badSet = $this->createListingScreenshot($appId, $listingId, ID::unique(), $small, ['set' => 'banner']);
        $this->assertSame(400, $badSet['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $badSet['body']['type']);

        $secondIcon = $this->createListingScreenshot($appId, $listingId, ID::unique(), $this->image(512, 512, false), ['set' => 'icon']);
        $this->assertSame(400, $secondIcon['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $secondIcon['body']['type']);

        $ios = $this->createListing($appId, ['platform' => 'ios']);
        $this->assertSame(201, $ios['headers']['status-code']);
        $attested = $this->createListingScreenshot($appId, $ios['body']['$id'], ID::unique(), $small, ['set' => 'APP_IPHONE_67']);
        $this->assertSame(400, $attested['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $attested['body']['type']);

        $iosShot = $this->createListingScreenshot($appId, $ios['body']['$id'], ID::unique(), $small, ['set' => 'APP_IPHONE_67', 'aiGeneratedState' => null]);
        $this->assertSame(201, $iosShot['headers']['status-code']);
        $this->assertSame('APP_IPHONE_67', $iosShot['body']['screenshots'][0]['set']);
        $this->assertArrayNotHasKey('aiGeneratedState', $iosShot['body']['screenshots'][0]);

        $bundle = $this->createListingScreenshot($appId, $listingId, ID::unique(), $small, name: 'screenshot.aab');
        $this->assertSame(400, $bundle['headers']['status-code']);
        $this->assertSame('storage_file_type_unsupported', $bundle['body']['type']);

        $disguised = $this->createListingScreenshot($appId, $listingId, ID::unique(), 'not an image');
        $this->assertSame(400, $disguised['headers']['status-code']);
        $this->assertSame('storage_file_type_unsupported', $disguised['body']['type']);

        $misnamedId = ID::unique();
        $misnamed = $this->createListingScreenshot($appId, $listingId, $misnamedId, $small, name: 'screenshot.jpg');
        $this->assertSame(400, $misnamed['headers']['status-code']);
        $this->assertSame('storage_file_type_unsupported', $misnamed['body']['type']);

        $jpeg = $this->createListingScreenshot($appId, $listingId, $misnamedId, $this->image(320, 640, false, true), name: 'screenshot.jpg');
        $this->assertSame(201, $jpeg['headers']['status-code'], 'A refused upload must not keep its file or burn its screenshot ID');
        $this->assertSame($misnamedId, $jpeg['body']['screenshots'][5]['$id']);

        $reversed = $this->createListingScreenshot($appId, $listingId, ID::unique(), $small, range: 'bytes 10-5/' . \strlen($small));
        $this->assertSame(400, $reversed['headers']['status-code']);
        $this->assertSame('storage_invalid_content_range', $reversed['body']['type']);

        $orphan = $this->createListingScreenshot($appId, $listingId, ID::unique(), \substr($first, self::CHUNK), range: $this->range(self::CHUNK, \strlen($first), \strlen($first)), uploadId: $listingId);
        $this->assertSame(400, $orphan['headers']['status-code']);
        $this->assertSame('storage_invalid_content_range', $orphan['body']['type']);

        $oversized = $this->createListingScreenshot($appId, $listingId, ID::unique(), $small, range: $this->range(0, \strlen($small), self::IMAGE_LIMIT + 1));
        $this->assertSame(400, $oversized['headers']['status-code']);
        $this->assertSame('storage_invalid_file_size', $oversized['body']['type']);

        $unfixed = $this->createListingScreenshot($appId, $listingId, 'unique()', \substr($first, 0, self::CHUNK), range: $this->range(0, self::CHUNK, \strlen($first)));
        $this->assertSame(400, $unfixed['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unfixed['body']['type']);

        $reused = $this->createListingScreenshot($appId, $listingId, $screenshotId, $small);
        $this->assertSame(409, $reused['headers']['status-code']);
        $this->assertSame('resource_already_exists', $reused['body']['type']);

        $mismatched = $this->createListingScreenshot($appId, $listingId, ID::unique(), \substr($first, self::CHUNK), range: $this->range(self::CHUNK, \strlen($first), \strlen($first)), uploadId: ID::unique());
        $this->assertSame(400, $mismatched['headers']['status-code']);
        $this->assertSame('storage_invalid_appwrite_id', $mismatched['body']['type']);

        $missing = $this->createListingScreenshot($appId, ID::unique(), ID::unique(), $small);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $missing['body']['type']);

        $unchanged = $this->getListing($appId, $listingId);
        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertCount(6, $unchanged['body']['screenshots']);
        $this->assertSame(7, $unchanged['body']['revision']);
    }

    public function testDeleteListingScreenshot(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $listing = $this->createListing($appId);
        $this->assertSame(201, $listing['headers']['status-code']);
        $listingId = $listing['body']['$id'];
        $keptId = ID::unique();
        $removedId = ID::unique();
        $kept = $this->createListingScreenshot($appId, $listingId, $keptId, $this->image(320, 640, false));
        $removed = $this->createListingScreenshot($appId, $listingId, $removedId, $this->image(320, 640, false));
        $this->assertSame(201, $kept['headers']['status-code']);
        $this->assertSame(201, $removed['headers']['status-code']);
        $this->assertSame(3, $removed['body']['revision']);

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteListingScreenshot($appId, $listingId, $removedId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $fetched = $this->getListing($appId, $listingId);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame([$keptId], \array_column($fetched['body']['screenshots'], '$id'));
        $this->assertSame(4, $fetched['body']['revision']);

        /**
         * Test for FAILURE
         */
        $again = $this->deleteListingScreenshot($appId, $listingId, $removedId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('storage_file_not_found', $again['body']['type']);

        $unknown = $this->deleteListingScreenshot($appId, $listingId, ID::unique());
        $this->assertSame(404, $unknown['headers']['status-code']);
        $this->assertSame('storage_file_not_found', $unknown['body']['type']);

        $reused = $this->createListingScreenshot($appId, $listingId, $removedId, $this->image(320, 640, false));
        $this->assertSame(409, $reused['headers']['status-code']);
        $this->assertSame('resource_already_exists', $reused['body']['type']);

        $missing = $this->deleteListingScreenshot($appId, ID::unique(), $keptId);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $missing['body']['type']);

        $this->assertSame(4, $this->getListing($appId, $listingId)['body']['revision']);
    }

    public function testDeleteListing(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createListing($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $listingId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteListing($appId, $listingId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $listing = $this->getListing($appId, $listingId);
        $this->assertSame(404, $listing['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $listing['body']['type']);

        $recreated = $this->createListing($appId);
        $this->assertSame(201, $recreated['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $again = $this->deleteListing($appId, $listingId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $again['body']['type']);

        $missing = $this->deleteListing(ID::unique(), $listingId);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createListing(string $appId, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/listings', $this->headers(), \array_filter([
            'listingId' => ID::unique(),
            'platform' => 'android',
            'locale' => 'en-US',
            ...$params,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getListing(string $appId, string $listingId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/listings/' . $listingId, $this->headers());
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listListings(string $appId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/listings', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function updateListing(string $appId, string $listingId, array $fields): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/listings/' . $listingId, $this->headers(), [
            'fields' => $fields,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteListing(string $appId, string $listingId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/listings/' . $listingId, $this->headers());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createListingScreenshot(
        string $appId,
        string $listingId,
        string $screenshotId,
        string $content,
        array $params = [],
        string $range = '',
        string $uploadId = '',
        string $name = 'screenshot.png',
    ): array {
        $headers = \array_filter([
            'content-type' => 'multipart/form-data',
            'content-range' => $range,
            'x-appwrite-id' => $uploadId,
        ], fn (string $value): bool => $value !== '');

        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/listings/' . $listingId . '/screenshots', \array_merge($this->headers(), $headers), [
            ...\array_filter([
                'screenshotId' => $screenshotId,
                'set' => 'phoneScreenshots',
                'aiGeneratedState' => self::NOT_AI_GENERATED,
                ...$params,
            ], fn (mixed $value): bool => $value !== null),
            'file' => new CURLFile('data://image/png;base64,' . \base64_encode($content), 'image/png', $name),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteListingScreenshot(string $appId, string $listingId, string $screenshotId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/listings/' . $listingId . '/screenshots/' . $screenshotId, $this->headers());
    }

    protected function image(int $width, int $height, bool $noise, bool $jpeg = false): string
    {
        $canvas = \imagecreatetruecolor($width, $height);
        $this->assertNotFalse($canvas);

        if ($noise) {
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    \imagesetpixel($canvas, $x, $y, \mt_rand(0, 0xFFFFFF));
                }
            }
        } else {
            \imagefill($canvas, 0, 0, \random_int(0, 0xFFFFFF));
        }

        $path = \tempnam(\sys_get_temp_dir(), 'appwrite-listing-');
        $this->assertIsString($path);
        $this->assertTrue($jpeg ? \imagejpeg($canvas, $path) : \imagepng($canvas, $path));
        $content = \file_get_contents($path);
        \unlink($path);
        $this->assertIsString($content);

        return $content;
    }

    private function range(int $start, int $end, int $size): string
    {
        return 'bytes ' . $start . '-' . ($end - 1) . '/' . $size;
    }
}
