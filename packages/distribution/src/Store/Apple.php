<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Utopia\Distribution\Account;
use Utopia\Distribution\App;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\JWT;
use Utopia\Distribution\Listing;
use Utopia\Distribution\Progress;
use Utopia\Distribution\Release;
use Utopia\Distribution\Signing;
use Utopia\Distribution\State;
use Utopia\Distribution\Store;
use Utopia\Distribution\Store\Apple\Bundle;
use Utopia\Distribution\Store\Apple\Certificate;
use Utopia\Distribution\Store\Apple\Profile;
use Utopia\Distribution\Store\Apple\Webhook;
use Utopia\Distribution\Upload;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;
use Utopia\Storage\Device;

class Apple extends Store
{
    public const string NAME = 'apple';

    public const string ENDPOINT = 'https://api.appstoreconnect.apple.com';

    public const string AUDIENCE = 'appstoreconnect-v1';

    public const int TOKEN_LIFETIME = 1200;

    public const int TOKEN_REFRESH = 900;

    public const int TOKEN_SKEW = 60;

    private const string PATH_APPS = '/v1/apps';

    private const string PATH_BUILDS = '/v1/builds';

    private const string PATH_WEBHOOKS = '/v1/webhooks';

    private const string PATH_WEBHOOK_PINGS = '/v1/webhookPings';

    private const string TYPE_APPS = 'apps';

    private const string TYPE_WEBHOOKS = 'webhooks';

    private const string TYPE_WEBHOOK_PINGS = 'webhookPings';

    private const string FIELDS_APPS = 'name,bundleId,sku,primaryLocale';

    private const string FIELDS_BUILDS = 'version';

    private const string SORT_BUILDS = '-uploadedDate';

    private const int LIMIT = 200;

    private const string HEADER_RATE_LIMIT = 'X-Rate-Limit';

    private const string RATE_REMAINING = 'user-hour-rem';

    private const int RATE_WINDOW = 3600;

    private const string CODE_AGREEMENTS = 'FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED';

    private const string CODE_PATH = 'PATH_ERROR';

    private const string CODE_STATE = 'STATE_ERROR';

    private const string PATH_CERTIFICATES = '/v1/certificates';

    private const string PATH_BUNDLE_IDS = '/v1/bundleIds';

    private const string PATH_BUNDLE_ID_CAPABILITIES = '/v1/bundleIdCapabilities';

    private const string PATH_PROFILES = '/v1/profiles';

    private const string TYPE_CERTIFICATES = 'certificates';

    private const string TYPE_BUNDLE_IDS = 'bundleIds';

    private const string TYPE_BUNDLE_ID_CAPABILITIES = 'bundleIdCapabilities';

    private const string TYPE_PROFILES = 'profiles';

    private const string RELATIONSHIP_CAPABILITIES = 'bundleIdCapabilities';

    private const string CERTIFICATE_LIMIT = 'You already have a current';

    private const string PEM_BEGIN = "-----BEGIN CERTIFICATE-----\n";

    private const string PEM_END = "-----END CERTIFICATE-----\n";

    private const int PEM_WIDTH = 64;

    private const string PATH_BUILD_UPLOADS = '/v1/buildUploads';

    private const string PATH_BUILD_UPLOAD_FILES = '/v1/buildUploadFiles';

    private const string TYPE_BUILDS = 'builds';

    private const string TYPE_BUILD_UPLOADS = 'buildUploads';

    private const string TYPE_BUILD_UPLOAD_FILES = 'buildUploadFiles';

    private const string FIELDS_BUILD_UPLOADS = 'state,build';

    private const string FIELDS_BUILD = 'version,processingState,usesNonExemptEncryption,buildAudienceType,expired';

    private const string ATTRIBUTE_ENCRYPTION = 'usesNonExemptEncryption';

    private const string INCLUDE_BUILD = 'build';

    private const string PLATFORM_IOS = 'IOS';

    private const string ASSET_TYPE = 'ASSET';

    private const string UTI_IPA = 'com.apple.ipa';

    private const string CHECKSUM_ALGORITHM = 'SHA_256';

    private const int CHECKSUM_CHUNK = 8388608;

    private const string UPLOAD_STATE_AWAITING = 'AWAITING_UPLOAD';

    private const string UPLOAD_STATE_COMPLETE = 'COMPLETE';

    private const string UPLOAD_STATE_FAILED = 'FAILED';

    private const array UPLOAD_STATES_PENDING = [self::UPLOAD_STATE_AWAITING, 'PROCESSING'];

    private const array DELIVERY_STATES_COMMITTED = ['UPLOAD_COMPLETE', 'COMPLETE'];

    private const string DELIVERY_STATE_FAILED = 'FAILED';

    private const string BUILD_STATE_VALID = 'VALID';

    private const string BUILD_STATE_PROCESSING = 'PROCESSING';

    private const array BUILD_STATES_FAILED = ['FAILED', 'INVALID'];

    private const string CODE_EXPIRED = 'UPLOAD_EXPIRED';

    private const string PATH_BETA_BUILD_LOCALIZATIONS = '/v1/betaBuildLocalizations';

    private const string PATH_BETA_APP_REVIEW_SUBMISSIONS = '/v1/betaAppReviewSubmissions';

    private const string TYPE_BETA_GROUPS = 'betaGroups';

    private const string TYPE_BETA_BUILD_LOCALIZATIONS = 'betaBuildLocalizations';

    private const string TYPE_BETA_APP_REVIEW_SUBMISSIONS = 'betaAppReviewSubmissions';

    private const string RELATIONSHIP_BETA_GROUPS = 'relationships/betaGroups';

    private const string RELATIONSHIP_BETA_APP_REVIEW_SUBMISSION = 'betaAppReviewSubmission';

    private const string RELATIONSHIP_BUILD_BETA_DETAIL = 'buildBetaDetail';

    private const string BETA_NOT_APPLICABLE = 'NOT_APPLICABLE';

    private const array BETA_STATUSES = [
        'PROCESSING' => State::STATUS_PROCESSING,
        'PROCESSING_EXCEPTION' => State::STATUS_REJECTED,
        'MISSING_EXPORT_COMPLIANCE' => State::STATUS_PENDING,
        'IN_EXPORT_COMPLIANCE_REVIEW' => State::STATUS_PENDING,
        'READY_FOR_BETA_SUBMISSION' => State::STATUS_PENDING,
        'WAITING_FOR_BETA_REVIEW' => State::STATUS_PENDING,
        'IN_BETA_REVIEW' => State::STATUS_REVIEWING,
        'BETA_APPROVED' => State::STATUS_APPROVED,
        'READY_FOR_BETA_TESTING' => State::STATUS_APPROVED,
        'IN_BETA_TESTING' => State::STATUS_PUBLISHED,
        'BETA_REJECTED' => State::STATUS_REJECTED,
        'EXPIRED' => State::STATUS_REJECTED,
    ];

    private const array BETA_MANUAL = [
        'MISSING_EXPORT_COMPLIANCE' => 'The build is missing export compliance information; answer it in App Store Connect',
        'IN_EXPORT_COMPLIANCE_REVIEW' => 'Apple is reviewing the export compliance documentation of the build',
    ];

    private const array BETA_FAILURES = [
        'PROCESSING_EXCEPTION' => 'TestFlight could not process the build',
        'BETA_REJECTED' => 'TestFlight review rejected the build',
        'EXPIRED' => 'The TestFlight build expired',
    ];

    private const string CODE_DUPLICATE = '.DUPLICATE';

    private const string PATH_APP_STORE_VERSIONS = '/v1/appStoreVersions';

    private const string PATH_LOCALIZATIONS = '/v1/appStoreVersionLocalizations';

    private const string PATH_PHASED_RELEASES = '/v1/appStoreVersionPhasedReleases';

    private const string PATH_RELEASE_REQUESTS = '/v1/appStoreVersionReleaseRequests';

    private const string PATH_REVIEW_SUBMISSIONS = '/v1/reviewSubmissions';

    private const string PATH_REVIEW_SUBMISSION_ITEMS = '/v1/reviewSubmissionItems';

    private const string TYPE_APP_STORE_VERSIONS = 'appStoreVersions';

    private const string TYPE_LOCALIZATIONS = 'appStoreVersionLocalizations';

    private const string TYPE_PHASED_RELEASES = 'appStoreVersionPhasedReleases';

    private const string TYPE_RELEASE_REQUESTS = 'appStoreVersionReleaseRequests';

    private const string TYPE_REVIEW_SUBMISSIONS = 'reviewSubmissions';

    private const string TYPE_REVIEW_SUBMISSION_ITEMS = 'reviewSubmissionItems';

    private const string RELATIONSHIP_BUILD = 'build';

    private const string RELATIONSHIP_PHASED_RELEASE = 'appStoreVersionPhasedRelease';

    private const string RELATIONSHIP_VERSION_FOR_REVIEW = 'appStoreVersionForReview';

    private const string RELATIONSHIP_ITEMS = 'items';

    private const string INCLUDE_VERSION = self::RELATIONSHIP_BUILD . ',' . self::RELATIONSHIP_PHASED_RELEASE;

    private const string INCLUDE_SUBMISSION = self::RELATIONSHIP_ITEMS . ',' . self::RELATIONSHIP_VERSION_FOR_REVIEW;

    private const int LIMIT_VERSIONS = 5;

    private const string RELEASE_AFTER_APPROVAL = 'AFTER_APPROVAL';

    private const string RELEASE_MANUAL = 'MANUAL';

    private const string VERSION_PREPARE_FOR_SUBMISSION = 'PREPARE_FOR_SUBMISSION';

    private const string VERSION_READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    private const string VERSION_WAITING_FOR_REVIEW = 'WAITING_FOR_REVIEW';

    private const string VERSION_IN_REVIEW = 'IN_REVIEW';

    private const string VERSION_WAITING_FOR_EXPORT_COMPLIANCE = 'WAITING_FOR_EXPORT_COMPLIANCE';

    private const string VERSION_ACCEPTED = 'ACCEPTED';

    private const string VERSION_PENDING_DEVELOPER_RELEASE = 'PENDING_DEVELOPER_RELEASE';

    private const string VERSION_PENDING_APPLE_RELEASE = 'PENDING_APPLE_RELEASE';

    private const string VERSION_PROCESSING_FOR_DISTRIBUTION = 'PROCESSING_FOR_DISTRIBUTION';

    private const string VERSION_READY_FOR_DISTRIBUTION = 'READY_FOR_DISTRIBUTION';

    private const string VERSION_REJECTED = 'REJECTED';

    private const string VERSION_METADATA_REJECTED = 'METADATA_REJECTED';

    private const string VERSION_INVALID_BINARY = 'INVALID_BINARY';

    private const string VERSION_DEVELOPER_REJECTED = 'DEVELOPER_REJECTED';

    private const string VERSION_REPLACED_WITH_NEW_VERSION = 'REPLACED_WITH_NEW_VERSION';

    private const array VERSION_EDITABLE = [
        self::VERSION_PREPARE_FOR_SUBMISSION,
        self::VERSION_DEVELOPER_REJECTED,
        self::VERSION_REJECTED,
        self::VERSION_METADATA_REJECTED,
        self::VERSION_INVALID_BINARY,
    ];

    private const array VERSION_PREPARABLE = [...self::VERSION_EDITABLE, self::VERSION_READY_FOR_REVIEW];

    private const string PHASED_INACTIVE = 'INACTIVE';

    private const string PHASED_ACTIVE = 'ACTIVE';

    private const string PHASED_PAUSED = 'PAUSED';

    private const string PHASED_COMPLETE = 'COMPLETE';

    private const array PHASED_SCHEDULE = [1 => 0.01, 2 => 0.02, 3 => 0.05, 4 => 0.10, 5 => 0.20, 6 => 0.50, 7 => 1.0];

    private const string SUBMISSION_READY_FOR_REVIEW = 'READY_FOR_REVIEW';

    private const string SUBMISSION_CANCELING = 'CANCELING';

    private const array SUBMISSION_OPEN = [
        self::SUBMISSION_READY_FOR_REVIEW,
        'WAITING_FOR_REVIEW',
        'IN_REVIEW',
        'UNRESOLVED_ISSUES',
        self::SUBMISSION_CANCELING,
        'COMPLETING',
    ];

    private const string PATH_APP_INFOS = '/v1/appInfos';

    private const string PATH_APP_INFO_LOCALIZATIONS = '/v1/appInfoLocalizations';

    private const string PATH_ASSET_IMAGES = '/v1/appAssetLibraryImages';

    private const string PATH_PLACEMENTS = '/v1/appAssetLibraryPlacements';

    private const string PATH_PLACEMENT_ORDERING_REQUESTS = '/v1/appAssetLibraryPlacementOrderingRequests';

    private const string RELATIONSHIP_ASSET_LIBRARY = 'assetLibrary';

    private const string TYPE_APP_INFOS = 'appInfos';

    private const string TYPE_APP_INFO_LOCALIZATIONS = 'appInfoLocalizations';

    private const string TYPE_ASSET_LIBRARIES = 'appAssetLibraries';

    private const string TYPE_ASSET_IMAGES = 'appAssetLibraryImages';

    private const string TYPE_PLACEMENTS = 'appAssetLibraryPlacements';

    private const string TYPE_PLACEMENT_ORDERING_REQUESTS = 'appAssetLibraryPlacementOrderingRequests';

    private const string RELATIONSHIP_PLACEMENTS = 'placements';

    private const string RELATIONSHIP_IMAGE = 'image';

    private const string SORT_PLACEMENTS = 'placementGroupPosition';

    private const string ASSET_CATEGORY = 'APP_SCREENSHOTS_AND_PREVIEWS';

    private const string ASSET_STATE_FAILED = 'FAILED';

    private const array ASSET_STATES_PENDING = ['AWAITING_UPLOAD', 'UPLOAD_COMPLETE'];

    private const array ASSET_STATES_READY = ['COMPLETE', 'PREPARE_FOR_SUBMISSION', 'READY_FOR_REVIEW', 'WAITING_FOR_REVIEW', 'IN_REVIEW', 'ACCEPTED', 'APPROVED'];

    private const string PLACEMENT_TYPE = 'APP_SCREENSHOT';

    private const array APP_INFO_EDITABLE = ['PREPARE_FOR_SUBMISSION', 'DEVELOPER_REJECTED', 'REJECTED'];

    private const array LISTING_VERSION_FIELDS = [
        Listing::FIELD_DESCRIPTION => 'description',
        Listing::FIELD_KEYWORDS => 'keywords',
        Listing::FIELD_PROMOTIONAL_TEXT => 'promotionalText',
        Listing::FIELD_SUPPORT_URL => 'supportUrl',
        Listing::FIELD_MARKETING_URL => 'marketingUrl',
    ];

    private const array LISTING_APP_INFO_FIELDS = [
        Listing::FIELD_NAME => 'name',
        Listing::FIELD_SUBTITLE => 'subtitle',
        Listing::FIELD_PRIVACY_URL => 'privacyPolicyUrl',
    ];

    private readonly JWT $jwt;

    private readonly Factory $factory;

    private ?string $token = null;

    private int $minted = 0;

    private ?int $exhausted = null;

    public function __construct(
        private readonly Credential\Apple $credential,
        ClientInterface $client,
        string $endpoint = self::ENDPOINT,
    ) {
        parent::__construct($client, $endpoint);

        $this->jwt = new JWT($credential->privateKey, JWT::ES256, $credential->keyId);
        $this->factory = new Factory();
    }

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function account(): Account
    {
        $this->request(Method::GET, self::PATH_APPS, ['limit' => 1]);

        return new Account($this->credential->issuerId);
    }

    #[\Override]
    public function app(string $identifier): App
    {
        $body = $this->request(Method::GET, self::PATH_APPS, [
            'filter' => ['bundleId' => $identifier],
            'fields' => ['apps' => self::FIELDS_APPS],
            'limit' => self::LIMIT,
        ]);

        foreach ($this->resources($body) as $resource) {
            $id = $resource['id'] ?? null;

            if (\is_string($id) && $id !== '' && $this->attribute($resource, 'bundleId') === $identifier) {
                return new App($id, $identifier, $this->attribute($resource, 'name'));
            }
        }

        throw new NotFound("App Store Connect has no app with bundle id \"{$identifier}\"");
    }

    #[\Override]
    public function latestVersionCode(string $identifier): int
    {
        $app = $this->app($identifier);

        $body = $this->request(Method::GET, self::PATH_BUILDS, [
            'filter' => ['app' => $app->id],
            'sort' => self::SORT_BUILDS,
            'limit' => self::LIMIT,
            'fields' => ['builds' => self::FIELDS_BUILDS],
        ]);

        $latest = 0;

        foreach ($this->resources($body) as $resource) {
            $version = $this->attribute($resource, 'version');

            if ($version !== null && ctype_digit($version)) {
                $latest = max($latest, (int) $version);
            }
        }

        return $latest;
    }

    #[\Override]
    public function release(Release $release, array $external): array
    {
        return match ($release->track) {
            Release::TRACK_TESTFLIGHT, Release::TRACK_TESTFLIGHT_EXTERNAL => $this->releaseTestFlight($release, $external),
            Release::TRACK_PRODUCTION => $this->releaseAppStore($release, $external),
            default => throw $this->unknown($release->track),
        };
    }

    #[\Override]
    public function state(array $external, string $track): State
    {
        return match ($track) {
            Release::TRACK_TESTFLIGHT, Release::TRACK_TESTFLIGHT_EXTERNAL => $this->stateTestFlight($external, $track),
            Release::TRACK_PRODUCTION => $this->stateAppStore($external),
            default => throw $this->unknown($track),
        };
    }

    #[\Override]
    public function rollout(array $external, string $track, float $fraction): void
    {
        $this->production($track);
        $this->rolloutAppStore($external, $fraction);
    }

    #[\Override]
    public function halt(array $external, string $track): void
    {
        $this->production($track);
        $this->haltAppStore($external);
    }

    #[\Override]
    public function resume(array $external, string $track): void
    {
        $this->production($track);
        $this->resumeAppStore($external);
    }

    #[\Override]
    public function withdraw(array $external): void
    {
        $track = $external['track'] ?? null;

        if (!\is_string($track) || $track === '') {
            throw new Invalid('The release has no track to withdraw it from');
        }

        if ($this->testFlight($track)) {
            throw new Invalid('A beta review cannot be withdrawn');
        }

        if ($track !== Release::TRACK_PRODUCTION) {
            throw $this->unknown($track);
        }

        $this->withdrawAppStore($external);
    }

    /**
     * @param list<string> $events
     */
    public function createWebhook(
        string $appId,
        string $url,
        #[\SensitiveParameter]
        string $secret,
        array $events = Webhook::EVENTS,
        string $name = Webhook::NAME,
    ): Webhook {
        $this->required($appId, 'App id');
        $this->required($url, 'Webhook url');
        $this->required($secret, 'Webhook secret');
        $this->required($name, 'Webhook name');

        if ($events === []) {
            throw new Invalid('Webhook events must not be empty');
        }

        $body = $this->request(Method::POST, self::PATH_WEBHOOKS, body: [
            'data' => [
                'type' => self::TYPE_WEBHOOKS,
                'attributes' => [
                    'enabled' => true,
                    'eventTypes' => $events,
                    'name' => $name,
                    'secret' => $secret,
                    'url' => $url,
                ],
                'relationships' => [
                    'app' => ['data' => ['type' => self::TYPE_APPS, 'id' => $appId]],
                ],
            ],
        ]);

        return $this->webhook($this->resource($body));
    }

    /**
     * @return list<Webhook>
     */
    public function listWebhooks(string $appId): array
    {
        $this->required($appId, 'App id');

        $body = $this->request(Method::GET, self::PATH_APPS . '/' . rawurlencode($appId) . '/' . self::TYPE_WEBHOOKS, [
            'limit' => self::LIMIT,
        ]);

        return array_map($this->webhook(...), $this->resources($body));
    }

    public function deleteWebhook(string $webhookId): void
    {
        $this->required($webhookId, 'Webhook id');

        $this->request(Method::DELETE, self::PATH_WEBHOOKS . '/' . rawurlencode($webhookId));
    }

    public function createWebhookPing(string $webhookId): void
    {
        $this->required($webhookId, 'Webhook id');

        $this->request(Method::POST, self::PATH_WEBHOOK_PINGS, body: [
            'data' => [
                'type' => self::TYPE_WEBHOOK_PINGS,
                'relationships' => [
                    'webhook' => ['data' => ['type' => self::TYPE_WEBHOOKS, 'id' => $webhookId]],
                ],
            ],
        ]);
    }

    /**
     * @return list<Certificate>
     */
    public function listCertificates(string $type = Certificate::TYPE_DISTRIBUTION): array
    {
        $this->required($type, 'Certificate type');

        $body = $this->request(Method::GET, self::PATH_CERTIFICATES, [
            'filter' => ['certificateType' => $type],
            'limit' => self::LIMIT,
        ]);

        return array_map($this->certificate(...), $this->resources($body));
    }

    public function createCertificate(string $csr, string $type = Certificate::TYPE_DISTRIBUTION): Certificate
    {
        $this->required($csr, 'Certificate signing request');
        $this->required($type, 'Certificate type');

        try {
            $body = $this->request(Method::POST, self::PATH_CERTIFICATES, body: [
                'data' => [
                    'type' => self::TYPE_CERTIFICATES,
                    'attributes' => [
                        'certificateType' => $type,
                        'csrContent' => $csr,
                    ],
                ],
            ]);
        } catch (Invalid $invalid) {
            foreach ($invalid->getIssues() as $issue) {
                if (str_contains($issue->message, self::CERTIFICATE_LIMIT)) {
                    throw new Conflict($invalid->getMessage(), $invalid->getIssues(), $invalid, $invalid->getBody());
                }
            }

            throw $invalid;
        }

        return $this->certificate($this->resource($body));
    }

    public function deleteCertificate(string $certificateId): void
    {
        $this->required($certificateId, 'Certificate id');

        $this->request(Method::DELETE, self::PATH_CERTIFICATES . '/' . rawurlencode($certificateId));
    }

    public function getBundle(string $identifier): Bundle
    {
        $this->required($identifier, 'Bundle identifier');

        $body = $this->request(Method::GET, self::PATH_BUNDLE_IDS, [
            'filter' => ['identifier' => $identifier],
            'include' => self::RELATIONSHIP_CAPABILITIES,
            'limit' => self::LIMIT,
        ]);

        foreach ($this->resources($body) as $resource) {
            if ($this->attribute($resource, 'identifier') === $identifier) {
                return $this->bundle($resource, $body);
            }
        }

        throw new NotFound("App Store Connect has no bundle id \"{$identifier}\"");
    }

    public function createBundle(string $identifier, string $name, string $platform = Bundle::PLATFORM_IOS): Bundle
    {
        $this->required($identifier, 'Bundle identifier');
        $this->required($name, 'Bundle name');
        $this->required($platform, 'Bundle platform');

        $body = $this->request(Method::POST, self::PATH_BUNDLE_IDS, body: [
            'data' => [
                'type' => self::TYPE_BUNDLE_IDS,
                'attributes' => [
                    'identifier' => $identifier,
                    'name' => $name,
                    'platform' => $platform,
                ],
            ],
        ]);

        return $this->bundle($this->resource($body), $body);
    }

    public function createCapability(string $bundleId, string $type): void
    {
        $this->required($bundleId, 'Bundle id');
        $this->required($type, 'Capability type');

        try {
            $this->request(Method::POST, self::PATH_BUNDLE_ID_CAPABILITIES, body: [
                'data' => [
                    'type' => self::TYPE_BUNDLE_ID_CAPABILITIES,
                    'attributes' => ['capabilityType' => $type],
                    'relationships' => [
                        'bundleId' => ['data' => ['type' => self::TYPE_BUNDLE_IDS, 'id' => $bundleId]],
                    ],
                ],
            ]);
        } catch (Invalid $invalid) {
            foreach ($invalid->getIssues() as $issue) {
                if (str_ends_with($issue->code, self::CODE_DUPLICATE)) {
                    return;
                }
            }

            throw $invalid;
        }
    }

    /**
     * @return list<Profile>
     */
    public function listProfiles(string $bundleId): array
    {
        $this->required($bundleId, 'Bundle id');

        $body = $this->request(Method::GET, self::PATH_BUNDLE_IDS . '/' . rawurlencode($bundleId) . '/' . self::TYPE_PROFILES, [
            'limit' => self::LIMIT,
        ]);

        return array_map($this->profile(...), $this->resources($body));
    }

    /**
     * @param list<string> $certificateIds
     */
    public function createProfile(string $name, string $type, string $bundleId, array $certificateIds): Profile
    {
        $this->required($name, 'Profile name');
        $this->required($type, 'Profile type');
        $this->required($bundleId, 'Bundle id');

        if ($certificateIds === []) {
            throw new Invalid('Profile certificates must not be empty');
        }

        $certificates = [];

        foreach ($certificateIds as $certificateId) {
            $this->required($certificateId, 'Certificate id');
            $certificates[] = ['type' => self::TYPE_CERTIFICATES, 'id' => $certificateId];
        }

        $body = $this->request(Method::POST, self::PATH_PROFILES, body: [
            'data' => [
                'type' => self::TYPE_PROFILES,
                'attributes' => [
                    'name' => $name,
                    'profileType' => $type,
                ],
                'relationships' => [
                    'bundleId' => ['data' => ['type' => self::TYPE_BUNDLE_IDS, 'id' => $bundleId]],
                    'certificates' => ['data' => $certificates],
                ],
            ],
        ]);

        return $this->profile($this->resource($body));
    }

    public function deleteProfile(string $profileId): void
    {
        $this->required($profileId, 'Profile id');

        $this->request(Method::DELETE, self::PATH_PROFILES . '/' . rawurlencode($profileId));
    }

    /**
     * @param array<mixed> $resource
     */
    private function certificate(array $resource): Certificate
    {
        $der = $this->content($resource, 'certificateContent', 'certificate');
        $pem = self::PEM_BEGIN . chunk_split(base64_encode($der), self::PEM_WIDTH, "\n") . self::PEM_END;

        try {
            $certificate = new Signing\Certificate($pem);
        } catch (Invalid $invalid) {
            throw new Unavailable('App Store Connect returned a certificate that cannot be parsed', previous: $invalid);
        }

        return new Certificate(
            $this->identify($resource),
            $this->attribute($resource, 'certificateType') ?? '',
            $this->attribute($resource, 'serialNumber') ?? '',
            $this->date($resource, 'expirationDate') ?? $certificate->expiresAt(),
            $certificate,
        );
    }

    /**
     * @param array<mixed> $resource
     * @param array<mixed> $body
     */
    private function bundle(array $resource, array $body): Bundle
    {
        $capabilities = [];

        foreach ($this->linkages($resource, self::RELATIONSHIP_CAPABILITIES) as $capabilityId) {
            $capability = $this->included($body, self::TYPE_BUNDLE_ID_CAPABILITIES, $capabilityId);
            $type = $capability === null ? null : $this->attribute($capability, 'capabilityType');

            if ($type !== null) {
                $capabilities[] = $type;
            }
        }

        return new Bundle(
            $this->identify($resource),
            $this->attribute($resource, 'identifier') ?? throw new Unavailable('App Store Connect returned a bundle id without an identifier'),
            $this->attribute($resource, 'name') ?? '',
            $this->attribute($resource, 'platform') ?? '',
            $this->attribute($resource, 'seedId') ?? throw new Unavailable('App Store Connect returned a bundle id without a seed id'),
            $capabilities,
        );
    }

    /**
     * @param array<mixed> $resource
     */
    private function profile(array $resource): Profile
    {
        return new Profile(
            $this->identify($resource),
            $this->attribute($resource, 'name') ?? '',
            $this->attribute($resource, 'profileType') ?? '',
            $this->attribute($resource, 'profileState') ?? '',
            $this->attribute($resource, 'uuid') ?? throw new Unavailable('App Store Connect returned a profile without a uuid'),
            $this->date($resource, 'expirationDate') ?? throw new Unavailable('App Store Connect returned a profile without an expiration date'),
            $this->content($resource, 'profileContent', 'profile'),
        );
    }

    /**
     * @param array<mixed> $resource
     */
    private function content(array $resource, string $name, string $kind): string
    {
        $content = $this->attribute($resource, $name);
        $decoded = $content === null ? false : base64_decode($content, true);

        if ($decoded === false || $decoded === '') {
            throw new Unavailable("App Store Connect returned a {$kind} without valid {$name}");
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $resource
     */
    private function date(array $resource, string $name): ?\DateTimeImmutable
    {
        $value = $this->attribute($resource, $name);

        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException $dateMalformedStringException) {
            throw new Unavailable("App Store Connect returned a malformed {$name}", previous: $dateMalformedStringException);
        }
    }

    /**
     * @param array<string, mixed>|null $checkpoint
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function upload(Upload $upload, ?array $checkpoint, ?Progress $progress = null): array
    {
        if ($upload->platform !== Upload::PLATFORM_IOS) {
            throw new Invalid("App Store Connect does not accept {$upload->platform} uploads");
        }

        $session = $this->restore($upload, $checkpoint);
        $resumed = $session !== null;
        $session ??= $this->reserve($upload, $progress);

        if (!$session['committed']) {
            try {
                $this->transfer($upload, $session, $progress);
            } catch (Unavailable $unavailable) {
                if (!$resumed || !$this->expired($unavailable)) {
                    throw $unavailable;
                }

                $this->discard(self::PATH_BUILD_UPLOADS, $session['buildUploadId']);
                $session = $this->reserve($upload, $progress);
                $this->transfer($upload, $session, $progress);
            }
        }

        return [
            'appId' => $session['appId'],
            'bundleId' => $session['bundleId'],
            'version' => $session['version'],
            'build' => $session['build'],
            'buildUploadId' => $session['buildUploadId'],
            'buildUploadFileId' => $session['buildUploadFileId'],
        ];
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function processed(array $external): ?array
    {
        $buildUploadId = $external['buildUploadId'] ?? null;
        $build = \is_string($buildUploadId) && $buildUploadId !== ''
            ? $this->uploaded($buildUploadId, $external)
            : $this->lookup($external);

        if ($build === null) {
            return null;
        }

        $state = $this->attribute($build, 'processingState');

        if ($state === self::BUILD_STATE_PROCESSING) {
            return null;
        }

        if ($state === self::BUILD_STATE_VALID) {
            return [...$external, 'buildId' => $this->identify($build)];
        }

        if (\in_array($state, self::BUILD_STATES_FAILED, true)) {
            throw new Invalid("App Store Connect marked the build {$state}", [new Issue($state, "App Store Connect marked the build {$state}")]);
        }

        throw new Unavailable('App Store Connect returned a build without a known processing state');
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function listing(Listing $listing, array $external): array
    {
        $appId = $this->text($external, 'appId') ?? throw new Invalid('The listing has no App Store Connect app id');
        $versionString = $this->text($external, 'version') ?? throw new Invalid('The listing has no version');

        $version = $this->appStoreVersion($appId, $versionString);
        $state = $this->attribute($version, 'appVersionState') ?? 'unknown';

        if (!\in_array($state, self::VERSION_EDITABLE, true)) {
            throw new Conflict(
                "App Store Connect version {$versionString} is {$state} and its listing cannot be edited",
                [new Issue(self::CODE_STATE, "The version is {$state}")],
            );
        }

        $information = $this->listingFields($listing, self::LISTING_APP_INFO_FIELDS);

        if ($information !== []) {
            $external['appInfoId'] = $this->listingAppInfo($appId, $listing->locale, $information);
        }

        $text = $this->listingFields($listing, self::LISTING_VERSION_FIELDS);

        if ($text === [] && $listing->screenshots === []) {
            return [...$external, 'complete' => true];
        }

        $localizationId = $this->listingLocalization($this->identify($version), $listing->locale, $text);

        if ($listing->screenshots === []) {
            return [...$external, 'complete' => true];
        }

        $device = $listing->device ?? throw new Invalid('Listing screenshots need a device to read them from');
        $libraryId = $this->text($external, 'libraryId') ?? $this->listingLibrary($appId);
        $images = $this->listingImages($libraryId, $listing->screenshots, $device, $this->listingAssets($external));
        $external = [...$external, 'libraryId' => $libraryId, 'assets' => $images['assets']];

        if (!$images['complete']) {
            return [...$external, 'complete' => false];
        }

        $this->listingPlacements($localizationId, $listing->screenshots, $images['assets']);

        return [...$external, 'complete' => true];
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    private function releaseTestFlight(Release $release, array $external): array
    {
        $buildId = $this->buildId($external);

        if ($release->encryption !== null) {
            $this->encryption($buildId, $release->encryption);
        }

        if ($release->notes !== []) {
            $this->betaNotes($buildId, $release->notes);
        }

        $groups = array_values(array_unique($release->groups));

        if ($groups !== []) {
            $this->request(Method::POST, self::PATH_BUILDS . '/' . rawurlencode($buildId) . '/' . self::RELATIONSHIP_BETA_GROUPS, body: [
                'data' => array_map(static fn (string $groupId): array => ['type' => self::TYPE_BETA_GROUPS, 'id' => $groupId], $groups),
            ]);
        }

        $external['track'] = $release->track;

        if ($release->track === Release::TRACK_TESTFLIGHT_EXTERNAL) {
            $external['betaReviewSubmissionId'] = $this->betaReviewSubmission($buildId) ?? $this->createBetaReviewSubmission($buildId);
        }

        return $external;
    }

    /**
     * @param array<string, mixed> $external
     */
    private function stateTestFlight(array $external, string $track): State
    {
        $buildId = $this->buildId($external);

        $detail = $this->resource($this->request(
            Method::GET,
            self::PATH_BUILDS . '/' . rawurlencode($buildId) . '/' . self::RELATIONSHIP_BUILD_BETA_DETAIL,
        ));

        $value = $this->attribute($detail, 'internalBuildState');

        if ($track === Release::TRACK_TESTFLIGHT_EXTERNAL) {
            $externalState = $this->attribute($detail, 'externalBuildState');
            $value = $externalState === self::BETA_NOT_APPLICABLE ? $value : $externalState;
        }

        if ($value === null || !isset(self::BETA_STATUSES[$value])) {
            throw new Unavailable('App Store Connect returned an unknown TestFlight build state "' . ($value ?? '') . '"');
        }

        $status = self::BETA_STATUSES[$value];
        $issues = [];

        if (isset(self::BETA_MANUAL[$value])) {
            $issues[] = new Issue($value, self::BETA_MANUAL[$value], manual: true);
        }

        if (isset(self::BETA_FAILURES[$value])) {
            $issues[] = new Issue($value, self::BETA_FAILURES[$value]);
        }

        return new State($status, $value, issues: $issues);
    }

    /**
     * @param array<string, mixed> $external
     */
    private function buildId(array $external): string
    {
        $buildId = $external['buildId'] ?? null;

        if (!\is_string($buildId) || trim($buildId) === '') {
            throw new Invalid('The release has no processed build');
        }

        return $buildId;
    }

    private function encryption(string $buildId, bool $encryption): void
    {
        $build = $this->resource($this->request(Method::GET, self::PATH_BUILDS . '/' . rawurlencode($buildId), [
            'fields' => [self::TYPE_BUILDS => self::ATTRIBUTE_ENCRYPTION],
        ]));
        $attributes = $build['attributes'] ?? null;

        if (\is_array($attributes) && \is_bool($attributes[self::ATTRIBUTE_ENCRYPTION] ?? null)) {
            return;
        }

        $this->request(Method::PATCH, self::PATH_BUILDS . '/' . rawurlencode($buildId), body: [
            'data' => [
                'type' => self::TYPE_BUILDS,
                'id' => $buildId,
                'attributes' => [self::ATTRIBUTE_ENCRYPTION => $encryption],
            ],
        ]);
    }

    /**
     * @param array<string, string> $notes
     */
    private function betaNotes(string $buildId, array $notes): void
    {
        $localizations = $this->resources($this->request(Method::GET, self::PATH_BUILDS . '/' . rawurlencode($buildId) . '/' . self::TYPE_BETA_BUILD_LOCALIZATIONS, [
            'limit' => self::LIMIT,
        ]));

        foreach ($notes as $locale => $whatsNew) {
            $this->localize(
                self::PATH_BETA_BUILD_LOCALIZATIONS,
                self::TYPE_BETA_BUILD_LOCALIZATIONS,
                $localizations,
                ['build' => ['data' => ['type' => self::TYPE_BUILDS, 'id' => $buildId]]],
                $locale,
                ['whatsNew' => $whatsNew],
            );
        }
    }

    private function betaReviewSubmission(string $buildId): ?string
    {
        try {
            $body = $this->request(Method::GET, self::PATH_BUILDS . '/' . rawurlencode($buildId) . '/' . self::RELATIONSHIP_BETA_APP_REVIEW_SUBMISSION);
        } catch (NotFound) {
            return null;
        }

        $data = $body['data'] ?? null;

        return \is_array($data) ? $this->text($data, 'id') : null;
    }

    private function createBetaReviewSubmission(string $buildId): string
    {
        try {
            $body = $this->request(Method::POST, self::PATH_BETA_APP_REVIEW_SUBMISSIONS, body: [
                'data' => [
                    'type' => self::TYPE_BETA_APP_REVIEW_SUBMISSIONS,
                    'relationships' => [
                        'build' => ['data' => ['type' => self::TYPE_BUILDS, 'id' => $buildId]],
                    ],
                ],
            ]);
        } catch (Conflict|Invalid $exception) {
            return $this->betaReviewSubmission($buildId) ?? throw $exception;
        }

        return $this->identify($this->resource($body));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->dispatch($method, $path, $query, $body);

        if ($response->getStatusCode() === 401) {
            $this->token = null;
            $response = $this->dispatch($method, $path, $query, $body);
        }

        $status = $response->getStatusCode();
        $contents = (string) $response->getBody();
        $decoded = $this->decode($contents);

        if ($status < 200 || $status >= 300) {
            throw $this->failure($status, $decoded, $contents);
        }

        if ($status === 204 || ($method !== Method::GET && trim($contents) === '')) {
            return [];
        }

        if ($decoded === null) {
            throw new Unavailable("App Store Connect returned {$status} without a JSON object");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    private function dispatch(string $method, string $path, array $query, ?array $body): ResponseInterface
    {
        $this->throttle();

        $uri = $this->endpoint . $path;
        $headers = [
            Header::AUTHORIZATION => 'Bearer ' . $this->token(),
            Header::ACCEPT => ContentType::JSON,
        ];

        try {
            $request = $body === null
                ? $this->factory->query($method, $uri, $query, $headers)
                : $this->factory->json($method, $uri, $body, $headers);
        } catch (\JsonException $jsonException) {
            throw new Invalid("App Store Connect request body cannot be encoded: {$jsonException->getMessage()}", previous: $jsonException);
        }

        $response = $this->send($request);

        $this->observe($response);

        return $response;
    }

    private function token(): string
    {
        $now = $this->now();

        if ($this->token === null || $now - $this->minted >= self::TOKEN_REFRESH) {
            $issued = $now - self::TOKEN_SKEW;
            $this->token = $this->jwt->encode([
                'iss' => $this->credential->issuerId,
                'iat' => $issued,
                'exp' => $issued + self::TOKEN_LIFETIME,
                'aud' => self::AUDIENCE,
            ]);
            $this->minted = $now;
        }

        return $this->token;
    }

    private function throttle(): void
    {
        if ($this->exhausted === null) {
            return;
        }

        if ($this->now() - $this->exhausted >= self::RATE_WINDOW) {
            $this->exhausted = null;

            return;
        }

        throw new RateLimited('App Store Connect hourly request allowance for this key is spent');
    }

    private function observe(ResponseInterface $response): void
    {
        $remaining = $this->remaining($response->getHeaderLine(self::HEADER_RATE_LIMIT));

        if ($remaining !== null) {
            $this->exhausted = $remaining === 0 ? $this->now() : null;
        }
    }

    private function remaining(string $header): ?int
    {
        foreach (explode(';', $header) as $entry) {
            $pair = explode(':', $entry, 2);

            if (\count($pair) === 2 && trim($pair[0]) === self::RATE_REMAINING) {
                $value = trim($pair[1]);

                return ctype_digit($value) ? (int) $value : null;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $contents): ?array
    {
        $body = json_decode($contents, true);

        return \is_array($body) ? $body : null;
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<mixed>>
     */
    private function resources(array $body): array
    {
        $data = $body['data'] ?? null;

        if (!\is_array($data)) {
            throw new Unavailable('App Store Connect returned a response without a data array');
        }

        return $this->objects($data);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    private function resource(array $body): array
    {
        $data = $body['data'] ?? null;

        if (!\is_array($data) || $this->text($data, 'id') === null) {
            throw new Unavailable('App Store Connect returned a response without a data object');
        }

        return $data;
    }

    /**
     * @param array<mixed> $resource
     */
    private function relationship(array $resource, string $name): ?string
    {
        $relationships = $resource['relationships'] ?? null;
        $relationship = \is_array($relationships) ? ($relationships[$name] ?? null) : null;
        $data = \is_array($relationship) ? ($relationship['data'] ?? null) : null;

        return \is_array($data) ? $this->text($data, 'id') : null;
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>|null
     */
    private function included(array $body, string $type, string $id): ?array
    {
        foreach ($this->objects($body['included'] ?? null) as $resource) {
            if ($this->text($resource, 'type') === $type && $this->text($resource, 'id') === $id) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @return list<array<mixed>>
     */
    private function objects(mixed $values): array
    {
        $objects = [];

        foreach (\is_array($values) ? $values : [] as $value) {
            if (\is_array($value)) {
                $objects[] = $value;
            }
        }

        return $objects;
    }

    /**
     * @param array<mixed> $resource
     */
    private function attribute(array $resource, string $name): ?string
    {
        $attributes = $resource['attributes'] ?? null;
        $value = \is_array($attributes) ? ($attributes[$name] ?? null) : null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed>|null $body
     */
    private function failure(int $status, ?array $body, string $contents): Exception
    {
        $errors = $this->objects($body['errors'] ?? null);
        $code = $this->text($errors[0] ?? [], 'code') ?? '';
        $issues = $this->issues($errors);

        $message = "App Store Connect returned {$status}";
        if ($issues !== []) {
            $message .= " {$issues[0]->code}: {$issues[0]->message}";
        }

        return match (true) {
            $status === 401 => new Unauthorized($message, $issues, credential: true, body: $contents),
            $status === 403 => new Unauthorized($message, $issues, body: $contents),
            $status === 404 && str_starts_with($code, self::CODE_PATH) => new Invalid($message, $issues, body: $contents),
            $status === 404, $status === 410 => new NotFound($message, $issues, body: $contents),
            $status === 409 && str_starts_with($code, self::CODE_STATE) => new Conflict($message, $issues, body: $contents),
            $status === 429 => new RateLimited($message, $issues, body: $contents),
            $status >= 400 && $status < 500 => new Invalid($message, $issues, body: $contents),
            default => new Unavailable($message, $issues, body: $contents),
        };
    }

    /**
     * @param list<array<mixed>> $errors
     *
     * @return list<Issue>
     */
    private function issues(array $errors): array
    {
        $issues = [];

        foreach ($errors as $error) {
            $issue = $this->issue($error);

            if ($issue instanceof Issue) {
                $issues[] = $issue;
            }

            $meta = $error['meta'] ?? null;
            $associated = \is_array($meta) ? ($meta['associatedErrors'] ?? null) : null;

            foreach ($this->objects($associated) as $nested) {
                array_push($issues, ...$this->issues($this->objects($nested)));
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed> $error
     */
    private function issue(array $error): ?Issue
    {
        $code = $this->text($error, 'code');

        if ($code === null) {
            return null;
        }

        $source = $error['source'] ?? null;
        $pointer = \is_array($source) ? $this->text($source, 'pointer') : null;

        return new Issue(
            $code,
            $this->text($error, 'detail') ?? $this->text($error, 'title') ?? $code,
            $pointer,
            str_starts_with($code, self::CODE_AGREEMENTS),
            $this->text($error, 'id'),
        );
    }

    /**
     * @param array<mixed> $values
     */
    private function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $resource
     */
    private function webhook(array $resource): Webhook
    {
        $attributes = $resource['attributes'] ?? null;
        $attributes = \is_array($attributes) ? $attributes : [];

        $events = \is_array($attributes['eventTypes'] ?? null) ? $attributes['eventTypes'] : [];

        return new Webhook(
            $this->identify($resource),
            $this->attribute($resource, 'name') ?? '',
            $this->attribute($resource, 'url') ?? '',
            array_values(array_filter($events, \is_string(...))),
            ($attributes['enabled'] ?? false) === true,
        );
    }

    private function required(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new Invalid("{$name} must not be empty");
        }
    }

    private function testFlight(string $track): bool
    {
        return $track === Release::TRACK_TESTFLIGHT || $track === Release::TRACK_TESTFLIGHT_EXTERNAL;
    }

    private function production(string $track): void
    {
        if ($track === Release::TRACK_PRODUCTION) {
            return;
        }

        if ($this->testFlight($track)) {
            throw new Invalid('A TestFlight release has no phased release');
        }

        throw $this->unknown($track);
    }

    private function unknown(string $track): Invalid
    {
        return new Invalid("App Store Connect has no track \"{$track}\"");
    }

    /**
     * @param array<string, mixed>|null $checkpoint
     *
     * @return array{appId: string, bundleId: string, version: string, build: string, buildUploadId: string, buildUploadFileId: string, parts: list<int>, operations: list<array{method: string, url: string, offset: int<0, max>, length: int<1, max>, partNumber: int<1, max>, headers: array<string, string>, expiration: int|null}>, committed: bool}|null
     */
    private function restore(Upload $upload, ?array $checkpoint): ?array
    {
        $appId = $checkpoint['appId'] ?? null;
        $buildUploadId = $checkpoint['buildUploadId'] ?? null;
        $buildUploadFileId = $checkpoint['buildUploadFileId'] ?? null;

        if (
            ($checkpoint['bundleId'] ?? null) !== $upload->identifier
            || ($checkpoint['version'] ?? null) !== $upload->version
            || ($checkpoint['build'] ?? null) !== $upload->build
            || !\is_string($appId) || $appId === ''
            || !\is_string($buildUploadId) || $buildUploadId === ''
        ) {
            return null;
        }

        try {
            $state = $this->uploadState($this->resource($this->request(Method::GET, self::PATH_BUILD_UPLOADS . '/' . rawurlencode($buildUploadId))));
        } catch (NotFound) {
            return null;
        }

        if (!\is_string($buildUploadFileId) || $buildUploadFileId === '') {
            $this->discard(self::PATH_BUILD_UPLOADS, $buildUploadId);

            return null;
        }

        $session = [
            'appId' => $appId,
            'bundleId' => $upload->identifier,
            'version' => $upload->version,
            'build' => $upload->build,
            'buildUploadId' => $buildUploadId,
            'buildUploadFileId' => $buildUploadFileId,
            'parts' => [],
            'operations' => [],
            'committed' => true,
        ];

        if ($state !== self::UPLOAD_STATE_AWAITING) {
            return $session;
        }

        try {
            $file = $this->resource($this->request(Method::GET, self::PATH_BUILD_UPLOAD_FILES . '/' . rawurlencode($buildUploadFileId)));
        } catch (NotFound) {
            $this->discard(self::PATH_BUILD_UPLOADS, $buildUploadId);

            return null;
        }

        $delivery = $this->deliveryState($file);

        if (\in_array($delivery, self::DELIVERY_STATES_COMMITTED, true)) {
            return $session;
        }

        $operations = $this->operations($file);
        $now = $this->now();
        $expired = array_any($operations, fn (array $operation): bool => $operation['expiration'] !== null && $operation['expiration'] <= $now);

        if ($expired || $delivery === self::DELIVERY_STATE_FAILED || $operations === []) {
            $this->discard(self::PATH_BUILD_UPLOADS, $buildUploadId);

            return null;
        }

        $numbers = array_column($operations, 'partNumber');
        $parts = \is_array($checkpoint['parts'] ?? null) ? $checkpoint['parts'] : [];

        return [
            ...$session,
            'parts' => array_values(array_filter($parts, fn (mixed $part): bool => \is_int($part) && \in_array($part, $numbers, true))),
            'operations' => $operations,
            'committed' => false,
        ];
    }

    /**
     * @return array{appId: string, bundleId: string, version: string, build: string, buildUploadId: string, buildUploadFileId: string, parts: list<int>, operations: list<array{method: string, url: string, offset: int<0, max>, length: int<1, max>, partNumber: int<1, max>, headers: array<string, string>, expiration: int|null}>, committed: bool}
     */
    private function reserve(Upload $upload, ?Progress $progress): array
    {
        $app = $this->app($upload->identifier);

        $buildUpload = $this->resource($this->request(Method::POST, self::PATH_BUILD_UPLOADS, body: [
            'data' => [
                'type' => self::TYPE_BUILD_UPLOADS,
                'attributes' => [
                    'cfBundleShortVersionString' => $upload->version,
                    'cfBundleVersion' => $upload->build,
                    'platform' => self::PLATFORM_IOS,
                ],
                'relationships' => [
                    'app' => ['data' => ['type' => self::TYPE_APPS, 'id' => $app->id]],
                ],
            ],
        ]));

        $checkpoint = [
            'appId' => $app->id,
            'bundleId' => $upload->identifier,
            'version' => $upload->version,
            'build' => $upload->build,
            'buildUploadId' => $this->identify($buildUpload),
        ];

        $progress?->save($checkpoint);

        $file = $this->resource($this->request(Method::POST, self::PATH_BUILD_UPLOAD_FILES, body: [
            'data' => [
                'type' => self::TYPE_BUILD_UPLOAD_FILES,
                'attributes' => [
                    'assetType' => self::ASSET_TYPE,
                    'fileName' => $upload->name,
                    'fileSize' => $upload->size,
                    'uti' => self::UTI_IPA,
                ],
                'relationships' => [
                    'buildUpload' => ['data' => ['type' => self::TYPE_BUILD_UPLOADS, 'id' => $checkpoint['buildUploadId']]],
                ],
            ],
        ]));

        $checkpoint = [...$checkpoint, 'buildUploadFileId' => $this->identify($file), 'parts' => []];

        $progress?->save($checkpoint);

        return [...$checkpoint, 'operations' => $this->operations($file), 'committed' => false];
    }

    /**
     * @param array{appId: string, bundleId: string, version: string, build: string, buildUploadId: string, buildUploadFileId: string, parts: list<int>, operations: list<array{method: string, url: string, offset: int<0, max>, length: int<1, max>, partNumber: int<1, max>, headers: array<string, string>, expiration: int|null}>, committed: bool} $session
     */
    private function transfer(Upload $upload, #[\SensitiveParameter] array $session, ?Progress $progress): void
    {
        $parts = $session['parts'];

        foreach ($session['operations'] as $operation) {
            if (\in_array($operation['partNumber'], $parts, true)) {
                continue;
            }

            if ($operation['offset'] + $operation['length'] > $upload->size) {
                throw new Unavailable("App Store Connect asked for part {$operation['partNumber']} beyond the end of the file");
            }

            $this->transmit($operation, $upload->device->read($upload->path, $operation['offset'], $operation['length']));

            $parts[] = $operation['partNumber'];

            $progress?->save([
                'appId' => $session['appId'],
                'bundleId' => $session['bundleId'],
                'version' => $session['version'],
                'build' => $session['build'],
                'buildUploadId' => $session['buildUploadId'],
                'buildUploadFileId' => $session['buildUploadFileId'],
                'parts' => $parts,
            ]);
        }

        $this->commit($session['buildUploadId'], $session['buildUploadFileId'], $this->checksum($upload));
    }

    /**
     * @param array{method: string, url: string, offset: int<0, max>, length: int<1, max>, partNumber: int<1, max>, headers: array<string, string>, expiration: int|null} $operation
     */
    private function transmit(#[\SensitiveParameter] array $operation, StreamInterface $body): void
    {
        $request = $this->factory->createRequest($operation['method'], $operation['url']);

        foreach ($operation['headers'] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $status = $this->send(
            $request
                ->withHeader(Header::CONTENT_LENGTH, (string) $operation['length'])
                ->withBody($body),
        )->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 403) {
            throw new Unavailable(
                "App Store Connect storage refused part {$operation['partNumber']} because the reservation expired",
                [new Issue(self::CODE_EXPIRED, 'The upload reservation expired')],
            );
        }

        throw new Unavailable("App Store Connect storage returned {$status} for part {$operation['partNumber']}");
    }

    /**
     * @param array<mixed> $resource
     *
     * @return list<array{method: string, url: string, offset: int<0, max>, length: int<1, max>, partNumber: int<1, max>, headers: array<string, string>, expiration: int|null}>
     */
    private function operations(#[\SensitiveParameter] array $resource): array
    {
        $attributes = $resource['attributes'] ?? null;
        $operations = [];

        foreach ($this->objects(\is_array($attributes) ? ($attributes['uploadOperations'] ?? null) : null) as $value) {
            $method = $this->text($value, 'method');
            $url = $this->text($value, 'url');
            $offset = $value['offset'] ?? null;
            $length = $value['length'] ?? null;
            $partNumber = $value['partNumber'] ?? null;

            if ($method === null || $url === null || !\is_int($offset) || $offset < 0 || !\is_int($length) || $length < 1 || !\is_int($partNumber) || $partNumber < 1) {
                throw new Unavailable('App Store Connect returned an unreadable upload operation');
            }

            $headers = [];

            foreach ($this->objects($value['requestHeaders'] ?? null) as $header) {
                $name = $this->text($header, 'name');
                $content = $header['value'] ?? null;

                if ($name !== null && \is_string($content)) {
                    $headers[$name] = $content;
                }
            }

            $expiration = $this->text($value, 'expiration');
            $expiration = $expiration === null ? false : strtotime($expiration);

            $operations[] = [
                'method' => $method,
                'url' => $url,
                'offset' => $offset,
                'length' => $length,
                'partNumber' => $partNumber,
                'headers' => $headers,
                'expiration' => $expiration === false ? null : $expiration,
            ];
        }

        usort($operations, fn (array $first, array $second): int => $first['partNumber'] <=> $second['partNumber']);

        return $operations;
    }

    private function checksum(Upload $upload): string
    {
        $context = hash_init('sha256');
        $offset = 0;

        while (($length = min(self::CHECKSUM_CHUNK, $upload->size - $offset)) > 0) {
            hash_update($context, $upload->device->read($upload->path, $offset, $length)->getContents());
            $offset += $length;
        }

        return hash_final($context);
    }

    private function commit(string $buildUploadId, string $buildUploadFileId, string $checksum): void
    {
        try {
            $this->request(Method::PATCH, self::PATH_BUILD_UPLOAD_FILES . '/' . rawurlencode($buildUploadFileId), body: [
                'data' => [
                    'type' => self::TYPE_BUILD_UPLOAD_FILES,
                    'id' => $buildUploadFileId,
                    'attributes' => [
                        'uploaded' => true,
                        'sourceFileChecksums' => [
                            'file' => ['hash' => $checksum, 'algorithm' => self::CHECKSUM_ALGORITHM],
                        ],
                    ],
                ],
            ]);
        } catch (Unavailable $unavailable) {
            if (!$this->landed($buildUploadId)) {
                throw $unavailable;
            }
        }
    }

    private function landed(string $buildUploadId): bool
    {
        try {
            $state = $this->uploadState($this->resource($this->request(Method::GET, self::PATH_BUILD_UPLOADS . '/' . rawurlencode($buildUploadId))));
        } catch (Exception) {
            return false;
        }

        return $state !== self::UPLOAD_STATE_AWAITING;
    }

    private function discard(string $path, string $id): void
    {
        try {
            $this->request(Method::DELETE, $path . '/' . rawurlencode($id));
        } catch (Exception) {
            // Best effort: a leftover upload or image is clutter, not a failure.
        }
    }

    private function expired(Unavailable $unavailable): bool
    {
        return array_any($unavailable->getIssues(), fn (Issue $issue): bool => $issue->code === self::CODE_EXPIRED);
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<mixed>|null
     */
    private function uploaded(string $buildUploadId, array $external): ?array
    {
        $body = $this->request(Method::GET, self::PATH_BUILD_UPLOADS . '/' . rawurlencode($buildUploadId), [
            'include' => self::INCLUDE_BUILD,
            'fields' => [
                self::TYPE_BUILD_UPLOADS => self::FIELDS_BUILD_UPLOADS,
                self::TYPE_BUILDS => self::FIELDS_BUILD,
            ],
        ]);

        $buildUpload = $this->resource($body);
        $state = $this->uploadState($buildUpload);

        if ($state === self::UPLOAD_STATE_FAILED) {
            $issues = $this->uploadIssues($buildUpload);

            throw new Invalid('App Store Connect rejected the build upload' . ($issues === [] ? '' : ": {$issues[0]->message}"), $issues);
        }

        if (\in_array($state, self::UPLOAD_STATES_PENDING, true)) {
            return null;
        }

        if ($state !== self::UPLOAD_STATE_COMPLETE) {
            throw new Unavailable('App Store Connect returned a build upload without a known state');
        }

        $buildId = $this->relationship($buildUpload, self::INCLUDE_BUILD);

        if ($buildId === null) {
            return $this->lookup($external);
        }

        return $this->included($body, self::TYPE_BUILDS, $buildId)
            ?? $this->resource($this->request(Method::GET, self::PATH_BUILDS . '/' . rawurlencode($buildId), [
                'fields' => [self::TYPE_BUILDS => self::FIELDS_BUILD],
            ]));
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<mixed>|null
     */
    private function lookup(array $external): ?array
    {
        $appId = $external['appId'] ?? null;

        if (!\is_string($appId) || $appId === '') {
            $appId = $this->app($this->recorded($external, 'bundleId'))->id;
        }

        $body = $this->request(Method::GET, self::PATH_BUILDS, [
            'filter' => [
                'app' => $appId,
                'version' => $this->recorded($external, 'build'),
                'preReleaseVersion.version' => $this->recorded($external, 'version'),
                'preReleaseVersion.platform' => self::PLATFORM_IOS,
            ],
            'fields' => [self::TYPE_BUILDS => self::FIELDS_BUILD],
            'limit' => 1,
        ]);

        return $this->resources($body)[0] ?? null;
    }

    /**
     * @param array<mixed> $buildUpload
     */
    private function uploadState(array $buildUpload): ?string
    {
        $attributes = $buildUpload['attributes'] ?? null;
        $state = \is_array($attributes) ? ($attributes['state'] ?? null) : null;

        return \is_array($state) ? $this->text($state, 'state') : null;
    }

    /**
     * @param array<mixed> $buildUpload
     *
     * @return list<Issue>
     */
    private function uploadIssues(array $buildUpload): array
    {
        $attributes = $buildUpload['attributes'] ?? null;
        $state = \is_array($attributes) ? ($attributes['state'] ?? null) : null;
        $issues = [];

        foreach ($this->objects(\is_array($state) ? ($state['errors'] ?? null) : null) as $error) {
            $code = $this->text($error, 'code');

            if ($code !== null) {
                $issues[] = new Issue($code, $this->text($error, 'description') ?? $code);
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed> $file
     */
    private function deliveryState(array $file): ?string
    {
        $attributes = $file['attributes'] ?? null;
        $state = \is_array($attributes) ? ($attributes['assetDeliveryState'] ?? null) : null;

        return \is_array($state) ? $this->text($state, 'state') : null;
    }

    /**
     * @param array<mixed> $resource
     */
    private function identify(array $resource): string
    {
        return $this->text($resource, 'id') ?? throw new Unavailable('App Store Connect returned a resource without an id');
    }

    /**
     * @param array<string, mixed> $external
     */
    private function recorded(array $external, string $key): string
    {
        $value = $external[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new Invalid("The upload has no {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    private function releaseAppStore(Release $release, array $external): array
    {
        $appId = $this->text($external, 'appId') ?? throw new Invalid('The release has no App Store Connect app id');
        $buildId = $this->buildId($external);

        $version = $this->appStoreVersion($appId, $release->version);
        $versionId = $this->identify($version);
        $phasedReleaseId = $this->relationship($version, self::RELATIONSHIP_PHASED_RELEASE);
        $editable = \in_array($this->attribute($version, 'appVersionState'), self::VERSION_PREPARABLE, true);

        if ($editable) {
            $this->appStoreReleaseType($version, $release->automatic ? self::RELEASE_AFTER_APPROVAL : self::RELEASE_MANUAL);

            if ($release->encryption !== null) {
                $this->encryption($buildId, $release->encryption);
            }

            if ($this->relationship($version, self::RELATIONSHIP_BUILD) !== $buildId) {
                $this->appStoreBuild($versionId, $buildId);
            }

            $first = ($release->notes !== [] || $release->fraction < 1.0) && !$this->appStoreReleased($appId);

            if ($release->notes !== [] && !$first) {
                $this->appStoreNotes($versionId, $release->notes);
            }

            $phasedReleaseId = $this->appStorePhasedRelease($versionId, $phasedReleaseId, $release->fraction < 1.0 && !$first);
        }

        $external = array_replace($external, [
            'track' => Release::TRACK_PRODUCTION,
            'versionId' => $versionId,
            'reviewSubmissionId' => $this->appStoreSubmission($appId, $versionId, $editable),
        ]);
        unset($external['phasedReleaseId']);

        return $phasedReleaseId === null ? $external : [...$external, 'phasedReleaseId' => $phasedReleaseId];
    }

    /**
     * @param array<string, mixed> $external
     */
    private function stateAppStore(array $external): State
    {
        [$version, $phasedRelease] = $this->appStoreRelease($external);
        $state = $this->attribute($version, 'appVersionState') ?? throw new Unavailable('App Store Connect returned a version without an appVersionState');

        return match ($state) {
            self::VERSION_PREPARE_FOR_SUBMISSION,
            self::VERSION_READY_FOR_REVIEW,
            self::VERSION_WAITING_FOR_REVIEW,
            self::VERSION_WAITING_FOR_EXPORT_COMPLIANCE => new State(State::STATUS_PENDING, $state),
            self::VERSION_IN_REVIEW, self::VERSION_ACCEPTED => new State(State::STATUS_REVIEWING, $state),
            self::VERSION_PENDING_DEVELOPER_RELEASE,
            self::VERSION_PENDING_APPLE_RELEASE,
            self::VERSION_PROCESSING_FOR_DISTRIBUTION => new State(State::STATUS_APPROVED, $state),
            self::VERSION_READY_FOR_DISTRIBUTION => $this->appStoreDistribution($state, $phasedRelease),
            self::VERSION_REJECTED, self::VERSION_METADATA_REJECTED, self::VERSION_INVALID_BINARY => new State(State::STATUS_REJECTED, $state),
            self::VERSION_DEVELOPER_REJECTED => new State(State::STATUS_REJECTED, $state, issues: [new Issue($state, 'The version was removed from review')]),
            self::VERSION_REPLACED_WITH_NEW_VERSION => new State(State::STATUS_REJECTED, $state, issues: [new Issue($state, 'A newer version replaced this version')]),
            default => new State(State::STATUS_PENDING, $state),
        };
    }

    /**
     * @param array<string, mixed> $external
     */
    private function rolloutAppStore(array $external, float $fraction): void
    {
        if ($fraction !== 1.0) {
            throw new Invalid('App Store phased releases follow a fixed seven-day schedule; only a full rollout of 1.0 can be requested');
        }

        [$version, $phasedRelease] = $this->appStoreRelease($external);
        $versionId = $this->identify($version);
        $state = $this->attribute($version, 'appVersionState') ?? throw new Unavailable('App Store Connect returned a version without an appVersionState');
        $phasedReleaseId = $phasedRelease === null ? null : $this->text($phasedRelease, 'id');
        $phase = $phasedRelease === null ? null : $this->attribute($phasedRelease, 'phasedReleaseState');

        if ($state === self::VERSION_PENDING_DEVELOPER_RELEASE) {
            if ($phasedReleaseId !== null && $phase === self::PHASED_INACTIVE) {
                $this->request(Method::DELETE, self::PATH_PHASED_RELEASES . '/' . rawurlencode($phasedReleaseId));
            }

            $this->request(Method::POST, self::PATH_RELEASE_REQUESTS, body: [
                'data' => [
                    'type' => self::TYPE_RELEASE_REQUESTS,
                    'relationships' => [
                        'appStoreVersion' => ['data' => ['type' => self::TYPE_APP_STORE_VERSIONS, 'id' => $versionId]],
                    ],
                ],
            ]);

            return;
        }

        if ($state !== self::VERSION_READY_FOR_DISTRIBUTION) {
            throw new Conflict("The App Store version is {$state} and cannot be rolled out");
        }

        if ($phasedReleaseId !== null && ($phase === self::PHASED_ACTIVE || $phase === self::PHASED_PAUSED)) {
            $this->appStorePhase($phasedReleaseId, self::PHASED_COMPLETE);
        }
    }

    /**
     * @param array<string, mixed> $external
     */
    private function haltAppStore(array $external): void
    {
        $this->appStorePace($external, self::PHASED_PAUSED);
    }

    /**
     * @param array<string, mixed> $external
     */
    private function resumeAppStore(array $external): void
    {
        $this->appStorePace($external, self::PHASED_ACTIVE);
    }

    /**
     * @param array<string, mixed> $external
     */
    private function withdrawAppStore(array $external): void
    {
        $reviewSubmissionId = $this->text($external, 'reviewSubmissionId') ?? throw new Invalid('The release has no review submission to withdraw');

        $this->request(Method::PATCH, self::PATH_REVIEW_SUBMISSIONS . '/' . rawurlencode($reviewSubmissionId), body: [
            'data' => [
                'type' => self::TYPE_REVIEW_SUBMISSIONS,
                'id' => $reviewSubmissionId,
                'attributes' => ['canceled' => true],
            ],
        ]);
    }

    /**
     * @return array<mixed>
     */
    private function appStoreVersion(string $appId, string $version): array
    {
        $this->required($appId, 'App id');
        $this->required($version, 'Version');

        $path = self::PATH_APPS . '/' . rawurlencode($appId) . '/' . self::TYPE_APP_STORE_VERSIONS;

        $matching = $this->resources($this->request(Method::GET, $path, [
            'filter' => ['platform' => self::PLATFORM_IOS, 'versionString' => $version],
            'include' => self::INCLUDE_VERSION,
            'limit' => self::LIMIT_VERSIONS,
        ]));

        foreach ($matching as $resource) {
            if ($this->attribute($resource, 'versionString') === $version) {
                return $resource;
            }
        }

        $editable = $this->resources($this->request(Method::GET, $path, [
            'filter' => ['platform' => self::PLATFORM_IOS, 'appVersionState' => implode(',', self::VERSION_EDITABLE)],
            'include' => self::INCLUDE_VERSION,
            'limit' => self::LIMIT_VERSIONS,
        ]))[0] ?? null;

        if ($editable !== null) {
            $editableId = $this->identify($editable);

            $updated = $this->resource($this->request(Method::PATCH, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($editableId), body: [
                'data' => [
                    'type' => self::TYPE_APP_STORE_VERSIONS,
                    'id' => $editableId,
                    'attributes' => ['versionString' => $version],
                ],
            ]));

            return array_replace($updated, ['relationships' => $editable['relationships'] ?? []]);
        }

        return $this->resource($this->request(Method::POST, self::PATH_APP_STORE_VERSIONS, body: [
            'data' => [
                'type' => self::TYPE_APP_STORE_VERSIONS,
                'attributes' => ['platform' => self::PLATFORM_IOS, 'versionString' => $version],
                'relationships' => [
                    'app' => ['data' => ['type' => self::TYPE_APPS, 'id' => $appId]],
                ],
            ],
        ]));
    }

    /**
     * @param array<mixed> $version
     */
    private function appStoreReleaseType(array $version, string $releaseType): void
    {
        if ($this->attribute($version, 'releaseType') === $releaseType) {
            return;
        }

        $versionId = $this->identify($version);

        $this->request(Method::PATCH, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($versionId), body: [
            'data' => [
                'type' => self::TYPE_APP_STORE_VERSIONS,
                'id' => $versionId,
                'attributes' => ['releaseType' => $releaseType],
            ],
        ]);
    }

    private function appStoreBuild(string $versionId, string $buildId): void
    {
        $this->request(Method::PATCH, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($versionId) . '/relationships/' . self::RELATIONSHIP_BUILD, body: [
            'data' => ['type' => self::TYPE_BUILDS, 'id' => $buildId],
        ]);
    }

    private function appStoreReleased(string $appId): bool
    {
        $released = $this->resources($this->request(Method::GET, self::PATH_APPS . '/' . rawurlencode($appId) . '/' . self::TYPE_APP_STORE_VERSIONS, [
            'filter' => [
                'platform' => self::PLATFORM_IOS,
                'appVersionState' => self::VERSION_READY_FOR_DISTRIBUTION . ',' . self::VERSION_REPLACED_WITH_NEW_VERSION,
            ],
            'limit' => 1,
        ]));

        return $released !== [];
    }

    /**
     * @param array<string, string> $notes
     */
    private function appStoreNotes(string $versionId, array $notes): void
    {
        $localizations = $this->resources($this->request(Method::GET, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($versionId) . '/' . self::TYPE_LOCALIZATIONS, [
            'limit' => self::LIMIT,
        ]));

        foreach ($notes as $locale => $whatsNew) {
            $this->localize(
                self::PATH_LOCALIZATIONS,
                self::TYPE_LOCALIZATIONS,
                $localizations,
                ['appStoreVersion' => ['data' => ['type' => self::TYPE_APP_STORE_VERSIONS, 'id' => $versionId]]],
                $locale,
                ['whatsNew' => $whatsNew],
            );
        }
    }

    /**
     * @param list<array<mixed>> $localizations
     * @param array<string, array{data: array{type: string, id: string}}> $relationships
     * @param array<string, string> $attributes
     */
    private function localize(string $path, string $type, array $localizations, array $relationships, string $locale, array $attributes): string
    {
        foreach ($localizations as $localization) {
            if ($this->attribute($localization, 'locale') !== $locale) {
                continue;
            }

            $localizationId = $this->identify($localization);
            $changed = array_filter(
                $attributes,
                fn (string $value, string $name): bool => $this->attribute($localization, $name) !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed !== []) {
                $this->request(Method::PATCH, $path . '/' . rawurlencode($localizationId), body: [
                    'data' => [
                        'type' => $type,
                        'id' => $localizationId,
                        'attributes' => $changed,
                    ],
                ]);
            }

            return $localizationId;
        }

        return $this->identify($this->resource($this->request(Method::POST, $path, body: [
            'data' => [
                'type' => $type,
                'attributes' => ['locale' => $locale, ...$attributes],
                'relationships' => $relationships,
            ],
        ])));
    }

    private function appStorePhasedRelease(string $versionId, ?string $phasedReleaseId, bool $phased): ?string
    {
        if ($phased && $phasedReleaseId === null) {
            $body = $this->request(Method::POST, self::PATH_PHASED_RELEASES, body: [
                'data' => [
                    'type' => self::TYPE_PHASED_RELEASES,
                    'attributes' => ['phasedReleaseState' => self::PHASED_INACTIVE],
                    'relationships' => [
                        'appStoreVersion' => ['data' => ['type' => self::TYPE_APP_STORE_VERSIONS, 'id' => $versionId]],
                    ],
                ],
            ]);

            return $this->text($this->resource($body), 'id');
        }

        if (!$phased && $phasedReleaseId !== null) {
            $this->request(Method::DELETE, self::PATH_PHASED_RELEASES . '/' . rawurlencode($phasedReleaseId));

            return null;
        }

        return $phasedReleaseId;
    }

    private function appStoreSubmission(string $appId, string $versionId, bool $editable): string
    {
        $submissions = $this->resources($this->request(Method::GET, self::PATH_REVIEW_SUBMISSIONS, [
            'filter' => [
                'app' => $appId,
                'platform' => self::PLATFORM_IOS,
                'state' => implode(',', self::SUBMISSION_OPEN),
            ],
            'include' => self::INCLUDE_SUBMISSION,
            'limit' => self::LIMIT_VERSIONS,
        ]));

        $ready = null;

        foreach ($submissions as $submission) {
            $state = $this->attribute($submission, 'state');
            $ours = $this->relationship($submission, self::RELATIONSHIP_VERSION_FOR_REVIEW) === $versionId;

            if ($state === self::SUBMISSION_READY_FOR_REVIEW) {
                $ready = $submission;
            } elseif ($ours && !$editable && $state !== self::SUBMISSION_CANCELING) {
                return $this->identify($submission);
            }
        }

        foreach ($submissions as $submission) {
            $state = $this->attribute($submission, 'state') ?? '';

            if ($state !== self::SUBMISSION_READY_FOR_REVIEW) {
                throw new Conflict("App Store Connect already has a review submission in state {$state} for this app");
            }
        }

        if (!$editable) {
            throw new Conflict('The App Store version cannot be submitted for review in its current state');
        }

        if ($ready === null) {
            $submissionId = $this->identify($this->resource($this->request(Method::POST, self::PATH_REVIEW_SUBMISSIONS, body: [
                'data' => [
                    'type' => self::TYPE_REVIEW_SUBMISSIONS,
                    'attributes' => ['platform' => self::PLATFORM_IOS],
                    'relationships' => [
                        'app' => ['data' => ['type' => self::TYPE_APPS, 'id' => $appId]],
                    ],
                ],
            ])));

            $this->appStoreSubmissionItem($submissionId, $versionId);
        } else {
            $submissionId = $this->identify($ready);

            if ($this->relationship($ready, self::RELATIONSHIP_VERSION_FOR_REVIEW) !== $versionId) {
                if ($this->linkages($ready, self::RELATIONSHIP_ITEMS) !== []) {
                    throw new Conflict('App Store Connect has a review submission ready for review that holds items Appwrite did not add');
                }

                $this->appStoreSubmissionItem($submissionId, $versionId);
            }
        }

        $this->request(Method::PATCH, self::PATH_REVIEW_SUBMISSIONS . '/' . rawurlencode($submissionId), body: [
            'data' => [
                'type' => self::TYPE_REVIEW_SUBMISSIONS,
                'id' => $submissionId,
                'attributes' => ['submitted' => true],
            ],
        ]);

        return $submissionId;
    }

    private function appStoreSubmissionItem(string $submissionId, string $versionId): void
    {
        try {
            $this->request(Method::POST, self::PATH_REVIEW_SUBMISSION_ITEMS, body: [
                'data' => [
                    'type' => self::TYPE_REVIEW_SUBMISSION_ITEMS,
                    'relationships' => [
                        'reviewSubmission' => ['data' => ['type' => self::TYPE_REVIEW_SUBMISSIONS, 'id' => $submissionId]],
                        'appStoreVersion' => ['data' => ['type' => self::TYPE_APP_STORE_VERSIONS, 'id' => $versionId]],
                    ],
                ],
            ]);
        } catch (Invalid $invalid) {
            foreach ($invalid->getIssues() as $issue) {
                if (str_ends_with($issue->code, self::CODE_DUPLICATE)) {
                    return;
                }
            }

            throw $invalid;
        }
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array{array<mixed>, array<mixed>|null}
     */
    private function appStoreRelease(array $external): array
    {
        $versionId = $this->text($external, 'versionId') ?? throw new Invalid('The release has no App Store version');

        $body = $this->request(Method::GET, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($versionId), [
            'include' => self::RELATIONSHIP_PHASED_RELEASE,
        ]);

        $version = $this->resource($body);
        $phasedReleaseId = $this->relationship($version, self::RELATIONSHIP_PHASED_RELEASE);

        return [$version, $phasedReleaseId === null ? null : $this->included($body, self::TYPE_PHASED_RELEASES, $phasedReleaseId)];
    }

    /**
     * @param array<string, mixed> $external
     */
    private function appStorePace(array $external, string $phase): void
    {
        $versionId = $this->text($external, 'versionId') ?? throw new Invalid('The release has no App Store version');

        try {
            $body = $this->request(Method::GET, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($versionId) . '/' . self::RELATIONSHIP_PHASED_RELEASE);
        } catch (NotFound) {
            $body = [];
        }

        if (!\is_array($body['data'] ?? null)) {
            throw new Invalid('The App Store version has no phased release');
        }

        $phasedRelease = $this->resource($body);
        $phasedReleaseId = $this->identify($phasedRelease);
        $current = $this->attribute($phasedRelease, 'phasedReleaseState') ?? '';

        if ($current === $phase) {
            return;
        }

        if ($current !== self::PHASED_ACTIVE && $current !== self::PHASED_PAUSED) {
            throw new Conflict("The phased release is {$current} and cannot change to {$phase}");
        }

        $this->appStorePhase($phasedReleaseId, $phase);
    }

    private function appStorePhase(string $phasedReleaseId, string $phase): void
    {
        $this->request(Method::PATCH, self::PATH_PHASED_RELEASES . '/' . rawurlencode($phasedReleaseId), body: [
            'data' => [
                'type' => self::TYPE_PHASED_RELEASES,
                'id' => $phasedReleaseId,
                'attributes' => ['phasedReleaseState' => $phase],
            ],
        ]);
    }

    /**
     * @param array<mixed>|null $phasedRelease
     */
    private function appStoreDistribution(string $state, ?array $phasedRelease): State
    {
        $phase = $phasedRelease === null ? null : $this->attribute($phasedRelease, 'phasedReleaseState');

        if ($phasedRelease === null || ($phase !== self::PHASED_ACTIVE && $phase !== self::PHASED_PAUSED)) {
            return new State(State::STATUS_PUBLISHED, $state);
        }

        $attributes = $phasedRelease['attributes'] ?? null;
        $day = \is_array($attributes) ? ($attributes['currentDayNumber'] ?? null) : null;
        $fraction = self::PHASED_SCHEDULE[max(1, min(\count(self::PHASED_SCHEDULE), \is_int($day) ? $day : 1))];

        return new State($phase === self::PHASED_ACTIVE ? State::STATUS_PUBLISHED : State::STATUS_HALTED, $state, $fraction);
    }

    /**
     * @param array<mixed> $resource
     *
     * @return list<string>
     */
    private function linkages(array $resource, string $name): array
    {
        $relationships = $resource['relationships'] ?? null;
        $relationship = \is_array($relationships) ? ($relationships[$name] ?? null) : null;
        $identifiers = [];

        foreach ($this->objects(\is_array($relationship) ? ($relationship['data'] ?? null) : null) as $linkage) {
            $id = $this->text($linkage, 'id');

            if ($id !== null) {
                $identifiers[] = $id;
            }
        }

        return $identifiers;
    }

    /**
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    private function listingFields(Listing $listing, array $map): array
    {
        $attributes = [];

        foreach ($map as $field => $attribute) {
            if (isset($listing->fields[$field])) {
                $attributes[$attribute] = $listing->fields[$field];
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function listingAppInfo(string $appId, string $locale, array $attributes): string
    {
        $appInfos = $this->resources($this->request(Method::GET, self::PATH_APPS . '/' . rawurlencode($appId) . '/' . self::TYPE_APP_INFOS, [
            'limit' => self::LIMIT,
        ]));

        foreach ($appInfos as $appInfo) {
            if (!\in_array($this->attribute($appInfo, 'state'), self::APP_INFO_EDITABLE, true)) {
                continue;
            }

            $appInfoId = $this->identify($appInfo);

            $localizations = $this->resources($this->request(Method::GET, self::PATH_APP_INFOS . '/' . rawurlencode($appInfoId) . '/' . self::TYPE_APP_INFO_LOCALIZATIONS, [
                'limit' => self::LIMIT,
            ]));

            $this->localize(
                self::PATH_APP_INFO_LOCALIZATIONS,
                self::TYPE_APP_INFO_LOCALIZATIONS,
                $localizations,
                ['appInfo' => ['data' => ['type' => self::TYPE_APP_INFOS, 'id' => $appInfoId]]],
                $locale,
                $attributes,
            );

            return $appInfoId;
        }

        throw new Conflict(
            'App Store Connect has no editable app information for this app',
            [new Issue(self::CODE_STATE, 'The app information is not editable')],
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    private function listingLocalization(string $versionId, string $locale, array $attributes): string
    {
        $localizations = $this->resources($this->request(Method::GET, self::PATH_APP_STORE_VERSIONS . '/' . rawurlencode($versionId) . '/' . self::TYPE_LOCALIZATIONS, [
            'limit' => self::LIMIT,
        ]));

        return $this->localize(
            self::PATH_LOCALIZATIONS,
            self::TYPE_LOCALIZATIONS,
            $localizations,
            ['appStoreVersion' => ['data' => ['type' => self::TYPE_APP_STORE_VERSIONS, 'id' => $versionId]]],
            $locale,
            $attributes,
        );
    }

    private function listingLibrary(string $appId): string
    {
        return $this->identify($this->resource($this->request(Method::GET, self::PATH_APPS . '/' . rawurlencode($appId) . '/' . self::RELATIONSHIP_ASSET_LIBRARY)));
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, string>
     */
    private function listingAssets(array $external): array
    {
        $assets = [];
        $recorded = $external['assets'] ?? null;

        foreach (\is_array($recorded) ? $recorded : [] as $digest => $imageId) {
            if (\is_string($imageId) && $imageId !== '') {
                $assets[(string) $digest] = $imageId;
            }
        }

        return $assets;
    }

    /**
     * @param list<array{set: string, path: string, size: int, width: int, height: int, digest: string}> $screenshots
     * @param array<string, string> $assets
     *
     * @return array{assets: array<string, string>, complete: bool}
     */
    private function listingImages(string $libraryId, array $screenshots, Device $device, array $assets): array
    {
        $complete = true;
        $checked = [];
        $missing = [];

        foreach ($screenshots as $screenshot) {
            $digest = $screenshot['digest'];

            if (isset($checked[$digest]) || isset($missing[$digest])) {
                continue;
            }

            $image = $this->listingImage($assets[$digest] ?? null);

            if ($image === null) {
                $missing[$digest] = $screenshot;

                continue;
            }

            $checked[$digest] = true;
            $complete = $this->listingReady($image) && $complete;
        }

        foreach ($missing as $digest => $screenshot) {
            $image = $this->listingUpload($libraryId, $screenshot, $device);
            $assets[(string) $digest] = $this->identify($image);
            $complete = $this->listingReady($image) && $complete;
        }

        return ['assets' => $assets, 'complete' => $complete];
    }

    /**
     * @return array<mixed>|null
     */
    private function listingImage(?string $imageId): ?array
    {
        if ($imageId === null) {
            return null;
        }

        try {
            return $this->resource($this->request(Method::GET, self::PATH_ASSET_IMAGES . '/' . rawurlencode($imageId)));
        } catch (NotFound) {
            return null;
        }
    }

    /**
     * @param array{set: string, path: string, size: int, width: int, height: int, digest: string} $screenshot
     *
     * @return array<mixed>
     */
    private function listingUpload(string $libraryId, array $screenshot, Device $device): array
    {
        $image = $this->resource($this->request(Method::POST, self::PATH_ASSET_IMAGES, body: [
            'data' => [
                'type' => self::TYPE_ASSET_IMAGES,
                'attributes' => [
                    'category' => self::ASSET_CATEGORY,
                    'fileName' => basename($screenshot['path']),
                    'fileSize' => $screenshot['size'],
                    'referenceName' => $screenshot['digest'],
                ],
                'relationships' => [
                    self::RELATIONSHIP_ASSET_LIBRARY => ['data' => ['type' => self::TYPE_ASSET_LIBRARIES, 'id' => $libraryId]],
                ],
            ],
        ]));

        $imageId = $this->identify($image);

        try {
            foreach ($this->operations($image) as $operation) {
                if ($operation['offset'] + $operation['length'] > $screenshot['size']) {
                    throw new Unavailable("App Store Connect asked for part {$operation['partNumber']} beyond the end of the screenshot");
                }

                $this->transmit($operation, $device->read($screenshot['path'], $operation['offset'], $operation['length']));
            }

            return $this->resource($this->request(Method::PATCH, self::PATH_ASSET_IMAGES . '/' . rawurlencode($imageId), body: [
                'data' => [
                    'type' => self::TYPE_ASSET_IMAGES,
                    'id' => $imageId,
                    'attributes' => ['uploaded' => true],
                ],
            ]));
        } catch (\Throwable $throwable) {
            $this->discard(self::PATH_ASSET_IMAGES, $imageId);

            throw $throwable;
        }
    }

    /**
     * @param array<mixed> $image
     */
    private function listingReady(array $image): bool
    {
        $state = $this->attribute($image, 'state') ?? throw new Unavailable('App Store Connect returned an image without a state');

        if (\in_array($state, self::ASSET_STATES_READY, true)) {
            return true;
        }

        if (\in_array($state, self::ASSET_STATES_PENDING, true)) {
            return false;
        }

        $name = $this->attribute($image, 'referenceName') ?? $this->identify($image);

        if ($state !== self::ASSET_STATE_FAILED) {
            throw new Unavailable("App Store Connect returned the screenshot {$name} in state {$state}, which cannot be placed");
        }

        $attributes = $image['attributes'] ?? null;
        $issues = [];

        foreach ($this->objects(\is_array($attributes) ? ($attributes['stateDetails'] ?? null) : null) as $detail) {
            $code = $this->text($detail, 'code');

            if ($code !== null) {
                $issues[] = new Issue($code, $this->text($detail, 'description') ?? $code);
            }
        }

        throw new Invalid(
            "App Store Connect could not process the screenshot {$name}",
            $issues === [] ? [new Issue(self::ASSET_STATE_FAILED, 'App Store Connect could not process the screenshot')] : $issues,
        );
    }

    /**
     * @param list<array{set: string, path: string, size: int, width: int, height: int, digest: string}> $screenshots
     * @param array<string, string> $assets
     */
    private function listingPlacements(string $localizationId, array $screenshots, array $assets): void
    {
        $desired = [];

        foreach ($screenshots as $screenshot) {
            $imageId = $assets[$screenshot['digest']] ?? throw new Unavailable('The screenshot was not uploaded');

            if (!\in_array($imageId, $desired[$screenshot['set']] ?? [], true)) {
                $desired[$screenshot['set']][] = $imageId;
            }
        }

        $body = $this->request(Method::GET, self::PATH_LOCALIZATIONS . '/' . rawurlencode($localizationId) . '/' . self::RELATIONSHIP_PLACEMENTS, [
            'include' => self::RELATIONSHIP_IMAGE,
            'sort' => self::SORT_PLACEMENTS,
            'limit' => self::LIMIT,
        ]);

        $existing = [];

        foreach ($this->resources($body) as $placement) {
            $group = $this->attribute($placement, 'placementGroup');

            if ($group !== null && isset($desired[$group]) && $this->attribute($placement, 'placementType') === self::PLACEMENT_TYPE) {
                $existing[$group][] = [
                    'id' => $this->identify($placement),
                    'imageId' => $this->relationship($placement, self::RELATIONSHIP_IMAGE),
                ];
            }
        }

        foreach ($desired as $group => $imageIds) {
            $this->listingPlace($localizationId, (string) $group, $imageIds, $existing[$group] ?? []);
        }
    }

    /**
     * @param list<string> $imageIds
     * @param list<array{id: string, imageId: string|null}> $placements
     */
    private function listingPlace(string $localizationId, string $group, array $imageIds, array $placements): void
    {
        $kept = [];

        foreach ($placements as $placement) {
            $imageId = $placement['imageId'];

            if ($imageId !== null && !isset($kept[$imageId]) && \in_array($imageId, $imageIds, true)) {
                $kept[$imageId] = $placement['id'];

                continue;
            }

            $this->request(Method::DELETE, self::PATH_PLACEMENTS . '/' . rawurlencode($placement['id']));
        }

        $current = array_values($kept);
        $ordered = [];
        $created = false;

        foreach ($imageIds as $imageId) {
            if (!isset($kept[$imageId])) {
                $kept[$imageId] = $this->listingPlacement($localizationId, $group, $imageId);
                $current[] = $kept[$imageId];
                $created = true;
            }

            $ordered[] = $kept[$imageId];
        }

        if (\count($ordered) < 2 || (!$created && $ordered === $current)) {
            return;
        }

        $this->request(Method::POST, self::PATH_PLACEMENT_ORDERING_REQUESTS, body: [
            'data' => [
                'type' => self::TYPE_PLACEMENT_ORDERING_REQUESTS,
                'attributes' => ['placementGroup' => $group],
                'relationships' => [
                    'orderedPlacements' => [
                        'data' => array_map(fn (string $placementId): array => ['type' => self::TYPE_PLACEMENTS, 'id' => $placementId], $ordered),
                    ],
                    'appStoreVersionLocalization' => ['data' => ['type' => self::TYPE_LOCALIZATIONS, 'id' => $localizationId]],
                ],
            ],
        ]);
    }

    private function listingPlacement(string $localizationId, string $group, string $imageId): string
    {
        return $this->identify($this->resource($this->request(Method::POST, self::PATH_PLACEMENTS, body: [
            'data' => [
                'type' => self::TYPE_PLACEMENTS,
                'attributes' => [
                    'placementType' => self::PLACEMENT_TYPE,
                    'placementGroup' => $group,
                ],
                'relationships' => [
                    self::RELATIONSHIP_IMAGE => ['data' => ['type' => self::TYPE_ASSET_IMAGES, 'id' => $imageId]],
                    'appStoreVersionLocalization' => ['data' => ['type' => self::TYPE_LOCALIZATIONS, 'id' => $localizationId]],
                ],
            ],
        ])));
    }
}
