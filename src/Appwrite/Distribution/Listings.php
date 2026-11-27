<?php

namespace Appwrite\Distribution;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Query;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Listing;
use Utopia\Distribution\Release;
use Utopia\Distribution\Store;
use Utopia\Storage\Device;

readonly class Listings
{
    public const string DIRECTORY = 'listings';

    public const int SNAPSHOT_LENGTH = 1000000;

    public const int LENGTH = 65536;

    public const int PAGE = 25;

    public const array FIELDS = [
        'android' => [
            Listing::FIELD_NAME,
            Listing::FIELD_SHORT_DESCRIPTION,
            Listing::FIELD_DESCRIPTION,
        ],
        'ios' => [
            Listing::FIELD_NAME,
            Listing::FIELD_SUBTITLE,
            Listing::FIELD_DESCRIPTION,
            Listing::FIELD_KEYWORDS,
            Listing::FIELD_PROMOTIONAL_TEXT,
            Listing::FIELD_SUPPORT_URL,
            Listing::FIELD_MARKETING_URL,
            Listing::FIELD_PRIVACY_URL,
        ],
    ];

    public const array IMAGE_TYPES = [
        'phoneScreenshots',
        'sevenInchScreenshots',
        'tenInchScreenshots',
        'tvScreenshots',
        'wearScreenshots',
        'icon',
        'featureGraphic',
        'tvBanner',
    ];

    public const array SINGLE_IMAGE_TYPES = ['icon', 'featureGraphic', 'tvBanner'];

    public const array AI_GENERATED_STATES = [
        'aiGeneratedStateUnspecified',
        'aiGeneratedStateNotAiGenerated',
        'aiGeneratedStateAiGeneratedDeveloperAttested',
    ];

    private const int ATTEMPTS = 3;

    private const array SCREENSHOT = ['$id', 'set', 'path', 'size', 'width', 'height', 'digest', 'aiGeneratedState'];

    private const string NO_EDIT = 'No open Google Play edit to write the listing into.';

    private const string CONCURRENT = 'The release listing changed concurrently ' . self::ATTEMPTS . ' times in a row.';

    public function __construct(
        private Database $dbForProject,
        private Device $deviceForDistribution,
    ) {
    }

    public static function path(Device $device, string $listingId, string $screenshotId, string $extension): string
    {
        return $device->getPath(self::DIRECTORY . "/{$listingId}/{$screenshotId}.{$extension}");
    }

    /**
     * @param array<array-key, mixed>|string $fields
     * @return array<string, string>
     */
    public static function fields(Platform $platform, array|string $fields): array
    {
        if (\is_string($fields)) {
            try {
                $fields = \json_decode($fields, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The listing fields must be a JSON object.');
            }
        }

        if (!\is_array($fields) || ($fields !== [] && \array_is_list($fields))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The listing fields must be a JSON object.');
        }

        $allowed = self::FIELDS[$platform->value];
        $valid = [];

        foreach ($fields as $field => $value) {
            if (!\in_array($field, $allowed, true)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Listing field \"{$field}\" is not supported on {$platform->value}. Allowed fields: " . \implode(', ', $allowed) . '.');
            }

            if (!\is_string($value)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Listing field \"{$field}\" must be a string.");
            }

            $valid[(string) $field] = $value;
        }

        $length = \strlen((string) \json_encode($valid));

        if ($length > self::LENGTH) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "The listing fields are too long ({$length} characters, at most " . self::LENGTH . ').');
        }

        return $valid;
    }

    public function snapshot(Document $release, Platform $platform, string $track): void
    {
        if ($track !== Release::TRACK_PRODUCTION) {
            return;
        }

        $release = $this->dbForProject->getDocument('distributionReleases', $release->getId());

        if ($release->isEmpty() || isset(self::listingOf($release)[$platform->value])) {
            return;
        }

        $listings = $this->dbForProject->find('distributionListings', [
            Query::equal('appInternalId', [(string) $release->getAttribute('appInternalId', '')]),
            Query::equal('platform', [$platform->value]),
            Query::orderAsc('$sequence'),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        if ($listings === []) {
            return;
        }

        $snapshot = ['listings' => \array_values(\array_map($this->entry(...), $listings))];

        if ($platform === Platform::Ios) {
            $snapshot = [...$snapshot, ...$this->inherit($release)];
        }

        $written = $this->write($release, static function (array $listing) use ($platform, $snapshot): ?array {
            if (isset($listing[$platform->value])) {
                return null;
            }

            $listing[$platform->value] = $snapshot;
            $length = \strlen((string) \json_encode($listing));

            if ($length > self::SNAPSHOT_LENGTH) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "The {$platform->value} store listings are too large to snapshot into the release ({$length} characters, at most " . self::SNAPSHOT_LENGTH . '). Shorten the listings or remove locales.');
            }

            return $listing;
        });

        if (!$written) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, self::CONCURRENT);
        }
    }

    /**
     * @param array<string, mixed> $external
     * @return array<string, mixed>
     */
    public function sync(Store $store, Document $submission, Document $release, Document $target, Document $artifact, array $external): array
    {
        if ($submission->getAttribute('track') !== Release::TRACK_PRODUCTION) {
            return $external;
        }

        $platform = Platform::tryFrom((string) $submission->getAttribute('platform', ''));

        if ($platform === null) {
            return $external;
        }

        $release = $this->dbForProject->getDocument('distributionReleases', $release->getId());
        $snapshot = self::snapshotOf($release, $platform);
        $entries = \is_array($snapshot['listings'] ?? null) ? \array_values($snapshot['listings']) : [];

        if ($entries === []) {
            return $external;
        }

        $identifier = (string) $target->getAttribute('identifier', '');
        $listings = \array_map(fn (mixed $entry): Listing => $this->listing($identifier, \is_array($entry) ? $entry : []), $entries);

        return match (Stores::provider($platform)) {
            Provider::Google => $this->google($store, $identifier, $listings, $external),
            Provider::Apple => $this->apple($store, $submission, $release, $artifact, $snapshot, $listings, $external),
        };
    }

    /**
     * @param array<string, mixed> $external
     */
    public static function isPending(array $external): bool
    {
        $listing = $external['listing'] ?? null;

        return \is_array($listing) && ($listing['complete'] ?? false) !== true;
    }

    /**
     * @param array<string, mixed> $external
     */
    public static function polls(array $external): int
    {
        $listing = $external['listing'] ?? null;

        return \is_array($listing) ? (int) ($listing['polls'] ?? 0) : 0;
    }

    public static function isSynced(Document $submission): bool
    {
        $external = $submission->getAttribute('external', []);
        $listing = \is_array($external) ? ($external['listing'] ?? null) : null;

        return \is_array($listing) && ($listing['complete'] ?? false) === true;
    }

    /**
     * @param list<Listing> $listings
     * @param array<string, mixed> $external
     * @return array<string, mixed>
     */
    private function google(Store $store, string $identifier, array $listings, array $external): array
    {
        if (($external['packageName'] ?? null) !== $identifier || empty($external['editId']) || ($external['committed'] ?? false) === true) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, self::NO_EDIT);
        }

        foreach ($listings as $listing) {
            $result = $store->listing($listing, $external);

            if (($result['committed'] ?? null) !== false) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, self::NO_EDIT);
            }
        }

        return [...$external, 'committed' => false, 'listing' => ['complete' => true, 'polls' => 0]];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param list<Listing> $listings
     * @param array<string, mixed> $external
     * @return array<string, mixed>
     */
    private function apple(Store $store, Document $submission, Document $release, Document $artifact, array $snapshot, array $listings, array $external): array
    {
        $polls = self::polls($external);
        $synced = \is_array($snapshot['synced'] ?? null) ? $snapshot['synced'] : [];

        if (\in_array($submission->getId(), $synced, true)) {
            return [...$external, 'listing' => ['complete' => true, 'polls' => $polls]];
        }

        $version = (string) ($artifact->getAttribute('versionName') ?: $release->getAttribute('version', ''));
        $libraryId = self::libraryOf($snapshot);
        $assets = self::assets($snapshot['assets'] ?? []);
        $complete = true;

        foreach ($listings as $listing) {
            $result = $store->listing($listing, \array_filter([
                'appId' => $external['appId'] ?? null,
                'version' => $version,
                'libraryId' => $libraryId,
                'assets' => $assets,
            ], static fn (mixed $value): bool => $value !== null && $value !== []));

            $libraryId = self::libraryOf($result) ?? $libraryId;
            $assets = [...$assets, ...self::assets($result['assets'] ?? [])];
            $complete = $complete && ($result['complete'] ?? false) === true;
        }

        $written = $this->write($release, static function (array $listing) use ($libraryId, $assets, $complete, $submission): ?array {
            $ios = \is_array($listing[Platform::Ios->value] ?? null) ? $listing[Platform::Ios->value] : [];
            $merged = $ios;
            $assets = [...self::assets($ios['assets'] ?? []), ...$assets];

            if ($assets !== []) {
                $merged['assets'] = $assets;
            }

            if ($libraryId !== null) {
                $merged['libraryId'] = $libraryId;
            }

            if ($complete) {
                $synced = \is_array($ios['synced'] ?? null) ? $ios['synced'] : [];
                $merged['synced'] = \array_values(\array_unique([...$synced, $submission->getId()]));
            }

            return $merged === $ios ? null : [...$listing, Platform::Ios->value => $merged];
        });

        if (!$written) {
            throw new Unavailable(self::CONCURRENT);
        }

        if ($complete) {
            return [...$external, 'listing' => ['complete' => true, 'polls' => $polls]];
        }

        $polls++;

        if (Submissions::backoff($polls) === null) {
            throw new Exception(Exception::DISTRIBUTION_STORE_UNAVAILABLE, 'App Store Connect did not finish processing the listing screenshots within a day.');
        }

        return [...$external, 'listing' => ['complete' => false, 'polls' => $polls]];
    }

    /**
     * @param \Closure(array<string, mixed>): ?array<string, mixed> $merge the new listing, or null to keep the stored one
     * @return bool false when every attempt lost to a concurrent write
     */
    private function write(Document $release, \Closure $merge): bool
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $release = $this->dbForProject->getDocument('distributionReleases', $release->getId());
            }

            $merged = $merge(self::listingOf($release));

            if ($merged === null) {
                return true;
            }

            try {
                $this->dbForProject->withRequestTimestamp(
                    new \DateTime($release->getUpdatedAt() ?? 'now'),
                    fn (): Document => $this->dbForProject->updateDocument('distributionReleases', $release->getId(), new Document([
                        'listing' => $merged,
                    ])),
                );

                return true;
            } catch (Conflict) {
                continue;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function inherit(Document $release): array
    {
        $cursor = $release;

        do {
            $earlier = $this->dbForProject->find('distributionReleases', [
                Query::select(['$id', '$sequence', 'listing']),
                Query::equal('appInternalId', [(string) $release->getAttribute('appInternalId', '')]),
                Query::orderDesc('$sequence'),
                Query::cursorAfter($cursor),
                Query::limit(self::PAGE),
            ]);

            foreach ($earlier as $candidate) {
                $ios = self::snapshotOf($candidate, Platform::Ios);
                $assets = self::assets($ios['assets'] ?? []);

                if ($assets !== []) {
                    $libraryId = self::libraryOf($ios);

                    return $libraryId === null ? ['assets' => $assets] : ['libraryId' => $libraryId, 'assets' => $assets];
                }

                $cursor = $candidate;
            }
        } while (\count($earlier) === self::PAGE);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Document $listing): array
    {
        $fields = $listing->getAttribute('fields', []);
        $screenshots = $listing->getAttribute('screenshots', []);

        return [
            '$id' => $listing->getId(),
            'locale' => (string) $listing->getAttribute('locale', ''),
            'revision' => (int) $listing->getAttribute('revision', 0),
            'fields' => \is_array($fields) ? $fields : [],
            'screenshots' => \array_values(\array_map(
                static fn (mixed $screenshot): array => \array_intersect_key(
                    $screenshot instanceof Document ? $screenshot->getArrayCopy() : (\is_array($screenshot) ? $screenshot : []),
                    \array_flip(self::SCREENSHOT),
                ),
                \is_array($screenshots) ? $screenshots : [],
            )),
        ];
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function listing(string $identifier, array $entry): Listing
    {
        $locale = (string) ($entry['locale'] ?? '');
        $screenshots = [];

        foreach (\is_array($entry['screenshots'] ?? null) ? $entry['screenshots'] : [] as $screenshot) {
            $screenshot = \is_array($screenshot) ? $screenshot : [];
            $path = (string) ($screenshot['path'] ?? '');
            $set = (string) ($screenshot['set'] ?? '');

            if ($path === '' || !$this->deviceForDistribution->exists($path)) {
                throw new Exception(Exception::DISTRIBUTION_CHECK_FAILED, "Screenshot {$set} of the {$locale} listing is missing from storage. Create a new release to submit the current listing.");
            }

            $screenshots[] = [
                'set' => $set,
                'path' => $path,
                'size' => (int) ($screenshot['size'] ?? 0),
                'width' => (int) ($screenshot['width'] ?? 0),
                'height' => (int) ($screenshot['height'] ?? 0),
                'digest' => (string) ($screenshot['digest'] ?? ''),
            ];
        }

        $fields = \is_array($entry['fields'] ?? null) ? $entry['fields'] : [];

        return new Listing(
            identifier: $identifier,
            locale: $locale,
            fields: \array_map(static fn (mixed $value): string => (string) $value, $fields),
            screenshots: $screenshots,
            device: $this->deviceForDistribution,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function listingOf(Document $release): array
    {
        $listing = $release->getAttribute('listing', []);

        return \is_array($listing) ? $listing : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshotOf(Document $release, Platform $platform): array
    {
        $snapshot = self::listingOf($release)[$platform->value] ?? null;

        return \is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function libraryOf(array $values): ?string
    {
        $libraryId = $values['libraryId'] ?? null;

        return \is_string($libraryId) && $libraryId !== '' ? $libraryId : null;
    }

    /**
     * @return array<string, string>
     */
    private static function assets(mixed $assets): array
    {
        $valid = [];

        foreach (\is_array($assets) ? $assets : [] as $digest => $imageId) {
            if (\is_string($imageId) && $imageId !== '') {
                $valid[(string) $digest] = $imageId;
            }
        }

        return $valid;
    }
}
