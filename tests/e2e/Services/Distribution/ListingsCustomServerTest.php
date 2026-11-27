<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;
use Utopia\System\System;

final class ListingsCustomServerTest extends Scope
{
    use DistributionBase;
    use ListingsBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const int SYNCED = 21;
    private const int INTERNAL = 22;

    public function testListingSyncedAtFirstProductionSubmission(): void
    {
        $prepared = $this->prepareGoogleRelease(self::SYNCED);
        $appId = $prepared['appId'];
        $fields = [
            'name' => 'Appwrite',
            'shortDescription' => 'Build fast.',
            'description' => 'Build like a team of hundreds.',
        ];

        $listing = $this->createListing($appId, ['fields' => $fields]);
        $this->assertSame(201, $listing['headers']['status-code']);
        $listingId = $listing['body']['$id'];

        $first = $this->image(1080, 1920, false);
        $second = $this->image(1080, 1920, false);
        $this->assertNotSame(\hash('sha256', $first), \hash('sha256', $second));
        $secondId = ID::unique();
        $this->assertSame(201, $this->createListingScreenshot($appId, $listingId, ID::unique(), $first)['headers']['status-code']);
        $this->assertSame(201, $this->createListingScreenshot($appId, $listingId, $secondId, $second)['headers']['status-code']);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'production',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('production', $created['body']['track']);

        $updated = $this->updateListing($appId, $listingId, [...$fields, 'description' => 'Edited after the release snapshot.']);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(204, $this->deleteListingScreenshot($appId, $listingId, $secondId)['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $published = $this->awaitSubmission($appId, $created['body']['$id'], 'published');
        $this->assertContains($published['step'], ['released', 'settled']);
        $this->assertSame('', $published['errorCode']);

        $synced = $this->googleListings($prepared['identifier']);
        $this->assertSame(['en-US'], \array_keys($synced));
        $this->assertSame('Appwrite', $synced['en-US']['title']);
        $this->assertSame('Build fast.', $synced['en-US']['shortDescription']);
        $this->assertSame('Build like a team of hundreds.', $synced['en-US']['fullDescription']);
        $this->assertSame(['phoneScreenshots' => [\hash('sha256', $first), \hash('sha256', $second)]], $synced['en-US']['images']);

        $current = $this->getListing($appId, $listingId);
        $this->assertSame(200, $current['headers']['status-code']);
        $this->assertSame('Edited after the release snapshot.', $current['body']['fields']['description']);
        $this->assertCount(1, $current['body']['screenshots']);

        if (System::getEnv('_APP_USAGE_STATS', 'enabled') !== 'disabled') {
            $headers = [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getNewKey(['usage.read']),
            ];

            $this->assertEventually(function () use ($headers, $appId): void {
                $usage = $this->client->call(Client::METHOD_GET, '/usage/events', $headers, [
                    'metrics' => [METRIC_DISTRIBUTION_LISTINGS_SYNCS],
                    'queries' => [Query::equal('resourceId', [$appId])->toString()],
                ]);
                $this->assertSame(200, $usage['headers']['status-code']);

                $total = 0;
                foreach ($usage['body']['metrics'] as $metric) {
                    if ($metric['metric'] === METRIC_DISTRIBUTION_LISTINGS_SYNCS) {
                        $total += (int) \array_sum(\array_column($metric['points'], 'value'));
                    }
                }

                $this->assertSame(1, $total, 'Metric ' . METRIC_DISTRIBUTION_LISTINGS_SYNCS . ' total');
            }, 120_000, 1000);
        }

        /**
         * Test for FAILURE
         */
        $other = $this->getProject(true);
        $foreignHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];
        $mirror = $this->client->call(Client::METHOD_POST, '/distribution/apps', $foreignHeaders, [
            'appId' => $appId,
            'name' => 'Mirror ' . \uniqid(),
            'framework' => 'android',
        ]);
        $this->assertSame(201, $mirror['headers']['status-code']);

        $foreign = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/listings/' . $listingId, $foreignHeaders);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_listing_not_found', $foreign['body']['type']);
    }

    public function testListingNotSyncedOnInternalTrack(): void
    {
        $prepared = $this->prepareGoogleRelease(self::INTERNAL);
        $appId = $prepared['appId'];

        $listing = $this->createListing($appId, ['fields' => ['name' => 'Appwrite']]);
        $this->assertSame(201, $listing['headers']['status-code']);
        $this->assertSame(201, $this->createListingScreenshot($appId, $listing['body']['$id'], ID::unique(), $this->image(1080, 1920, false))['headers']['status-code']);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $published = $this->awaitSubmission($appId, $created['body']['$id'], 'published');
        $this->assertSame('settled', $published['step']);
        $this->assertSame('', $published['errorCode']);

        /**
         * Test for FAILURE
         */
        $this->assertSame([], $this->googleListings($prepared['identifier']), 'An internal-track submission must not sync the store listing.');
    }
}
