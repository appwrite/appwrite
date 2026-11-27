<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple;

use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Client\Client;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

abstract class Base extends Action
{
    use HTTP;

    protected const string PREFIX = '/v1/mock/distribution/apple';
    protected const string SEEDS = self::PREFIX . '/seeds';
    protected const int TTL = 86400;
    protected const string CACHE_KEYS = 'mock:distribution:apple:keys:';
    protected const string CACHE_APPS = 'mock:distribution:apple:apps:';
    protected const string CACHE_APP = 'mock:distribution:apple:app:';
    protected const string CACHE_BUILDS = 'mock:distribution:apple:builds:';
    protected const string CACHE_BUILD = 'mock:distribution:apple:build:';
    protected const string CACHE_WEBHOOKS = 'mock:distribution:apple:webhooks:';
    protected const string CACHE_WEBHOOK = 'mock:distribution:apple:webhook:';
    protected const string CACHE_DELIVERIES = 'mock:distribution:apple:deliveries:';
    protected const string INTERNAL = 'internal';
    protected const string CODE_PARAMETER_INVALID = 'PARAMETER_ERROR.INVALID';
    protected const string CODE_PARAMETER_REQUIRED = 'PARAMETER_ERROR.REQUIRED';
    protected const string CODE_NOT_FOUND = 'NOT_FOUND';
    protected const string CODE_TYPE_INVALID = 'ENTITY_ERROR.TYPE.INVALID';
    protected const string CODE_ATTRIBUTE_REQUIRED = 'ENTITY_ERROR.ATTRIBUTE.REQUIRED';
    protected const string CODE_ATTRIBUTE_INVALID = 'ENTITY_ERROR.ATTRIBUTE.INVALID';
    protected const string CODE_RELATIONSHIP_REQUIRED = 'ENTITY_ERROR.RELATIONSHIP.REQUIRED';
    protected const string CODE_RELATIONSHIP_INVALID = 'ENTITY_ERROR.RELATIONSHIP.INVALID';

    private const string CACHE_REQUESTS = 'mock:distribution:apple:requests:';
    private const string HEADER_RATE_LIMIT = 'X-Rate-Limit';
    private const string ALGORITHM = 'ES256';
    private const string AUDIENCE = 'appstoreconnect-v1';
    private const int LIFETIME = 1200;
    private const int SKEW = 60;
    private const int RATE_LIMIT = 3500;
    private const int RATE_WINDOW = 3600;
    private const int SIGNATURE_PART = 32;
    private const string SIGNATURE_HEADER = 'x-apple-signature';
    private const string SIGNATURE_PREFIX = 'hmacsha256=';
    private const int DELIVERY_TIMEOUT = 10;
    private const string DELIVERY_SUCCEEDED = 'SUCCEEDED';
    private const string DELIVERY_FAILED = 'FAILED';

    protected const string LOCK_TARGET = 'mock';
    protected const int LOCK_TTL = 10;
    protected const float LOCK_WAIT = 10.0;

    protected const string CACHE_UPLOAD_BUILDS = 'mock:distribution:apple:upload:builds:';
    protected const string CACHE_UPLOAD_NUMBERS = 'mock:distribution:apple:upload:numbers:';
    protected const string CACHE_UPLOAD_FILES = 'mock:distribution:apple:upload:files:';
    protected const string CACHE_UPLOAD_SEEDS = 'mock:distribution:apple:upload:seeds:';
    protected const string CACHE_UPLOAD_RESERVATIONS = 'mock:distribution:apple:upload:reservations:';
    protected const string CACHE_UPLOAD_PARTS = 'mock:distribution:apple:upload:parts:';
    protected const string UPLOAD_STATE_AWAITING = 'AWAITING_UPLOAD';
    protected const string UPLOAD_STATE_COMPLETE = 'COMPLETE';
    protected const string UPLOAD_STATE_FAILED = 'FAILED';
    protected const string UPLOAD_ASSET = 'ASSET';
    protected const string UPLOAD_CODE_STATE = 'STATE_ERROR';

    protected const string CACHE_BETA_DETAIL = 'mock:distribution:apple:beta:detail:';
    protected const string CACHE_BETA_REVIEW = 'mock:distribution:apple:beta:review:';
    protected const string CACHE_BETA_SUBMISSION = 'mock:distribution:apple:beta:submission:';
    protected const string CACHE_BETA_LOCALIZATIONS = 'mock:distribution:apple:beta:localizations:';
    protected const string CACHE_BETA_LOCALIZATION = 'mock:distribution:apple:beta:localization:';
    protected const string CACHE_BETA_GROUPS = 'mock:distribution:apple:beta:groups:';
    protected const string CACHE_BETA_GROUP = 'mock:distribution:apple:beta:group:';
    protected const string BETA_EVENT = 'BUILD_BETA_DETAIL_EXTERNAL_BUILD_STATE_UPDATED';
    protected const string BETA_INTERNAL_ONLY = 'INTERNAL_ONLY';
    protected const string BETA_READY_FOR_SUBMISSION = 'READY_FOR_BETA_SUBMISSION';
    protected const string BETA_WAITING_FOR_REVIEW = 'WAITING_FOR_BETA_REVIEW';
    protected const array BETA_INTERNAL_STATES = [
        'PROCESSING',
        'PROCESSING_EXCEPTION',
        'MISSING_EXPORT_COMPLIANCE',
        'READY_FOR_BETA_TESTING',
        'IN_BETA_TESTING',
        'EXPIRED',
        'IN_EXPORT_COMPLIANCE_REVIEW',
    ];
    protected const array BETA_EXTERNAL_STATES = [
        'PROCESSING',
        'PROCESSING_EXCEPTION',
        'MISSING_EXPORT_COMPLIANCE',
        'READY_FOR_BETA_TESTING',
        'IN_BETA_TESTING',
        'EXPIRED',
        'READY_FOR_BETA_SUBMISSION',
        'IN_EXPORT_COMPLIANCE_REVIEW',
        'WAITING_FOR_BETA_REVIEW',
        'IN_BETA_REVIEW',
        'BETA_REJECTED',
        'BETA_APPROVED',
        'NOT_APPLICABLE',
    ];
    protected const array BETA_REVIEW_STATES = [
        'WAITING_FOR_BETA_REVIEW' => 'WAITING_FOR_REVIEW',
        'IN_BETA_REVIEW' => 'IN_REVIEW',
        'BETA_REJECTED' => 'REJECTED',
        'BETA_APPROVED' => 'APPROVED',
        'IN_BETA_TESTING' => 'APPROVED',
    ];

    protected const string CACHE_VERSION_VERSIONS = 'mock:distribution:apple:versions:';
    protected const string CACHE_VERSION_VERSION = 'mock:distribution:apple:version:';
    protected const string CACHE_VERSION_LOCALIZATION = 'mock:distribution:apple:version-localization:';
    protected const string CACHE_VERSION_PHASED = 'mock:distribution:apple:version-phased:';
    protected const string CACHE_VERSION_SUBMISSIONS = 'mock:distribution:apple:version-submissions:';
    protected const string CACHE_VERSION_SUBMISSION = 'mock:distribution:apple:version-submission:';
    protected const string CACHE_VERSION_REVIEWS = 'mock:distribution:apple:version-reviews:';
    protected const string VERSION_TYPE = 'appStoreVersions';
    protected const string VERSION_TYPE_LOCALIZATION = 'appStoreVersionLocalizations';
    protected const string VERSION_TYPE_PHASED = 'appStoreVersionPhasedReleases';
    protected const string VERSION_TYPE_SUBMISSION = 'reviewSubmissions';
    protected const string VERSION_TYPE_ITEM = 'reviewSubmissionItems';
    protected const string VERSION_PLATFORM = 'IOS';
    protected const string VERSION_EVENT = 'APP_STORE_VERSION_APP_VERSION_STATE_UPDATED';
    protected const string VERSION_CODE_STATE = 'STATE_ERROR';
    protected const string VERSION_CODE_DUPLICATE = 'ENTITY_ERROR.RELATIONSHIP.INVALID.DUPLICATE';
    protected const string VERSION_PREPARE_FOR_SUBMISSION = 'PREPARE_FOR_SUBMISSION';
    protected const string VERSION_READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    protected const string VERSION_WAITING_FOR_REVIEW = 'WAITING_FOR_REVIEW';
    protected const string VERSION_PENDING_DEVELOPER_RELEASE = 'PENDING_DEVELOPER_RELEASE';
    protected const string VERSION_READY_FOR_DISTRIBUTION = 'READY_FOR_DISTRIBUTION';
    protected const string VERSION_DEVELOPER_REJECTED = 'DEVELOPER_REJECTED';
    protected const string VERSION_REPLACED_WITH_NEW_VERSION = 'REPLACED_WITH_NEW_VERSION';
    protected const array VERSION_STATES = [
        self::VERSION_PREPARE_FOR_SUBMISSION,
        self::VERSION_READY_FOR_REVIEW,
        self::VERSION_WAITING_FOR_REVIEW,
        'IN_REVIEW',
        'WAITING_FOR_EXPORT_COMPLIANCE',
        'ACCEPTED',
        self::VERSION_PENDING_DEVELOPER_RELEASE,
        'PENDING_APPLE_RELEASE',
        'PROCESSING_FOR_DISTRIBUTION',
        self::VERSION_READY_FOR_DISTRIBUTION,
        'REJECTED',
        'METADATA_REJECTED',
        'INVALID_BINARY',
        self::VERSION_DEVELOPER_REJECTED,
        self::VERSION_REPLACED_WITH_NEW_VERSION,
    ];
    protected const array VERSION_EDITABLE = [
        self::VERSION_PREPARE_FOR_SUBMISSION,
        self::VERSION_DEVELOPER_REJECTED,
        'REJECTED',
        'METADATA_REJECTED',
        'INVALID_BINARY',
    ];
    protected const array VERSION_PREPARABLE = [...self::VERSION_EDITABLE, self::VERSION_READY_FOR_REVIEW];
    protected const array VERSION_RELEASED = [self::VERSION_READY_FOR_DISTRIBUTION, self::VERSION_REPLACED_WITH_NEW_VERSION];
    protected const array VERSION_RELEASE_TYPES = ['MANUAL', 'AFTER_APPROVAL', 'SCHEDULED'];
    protected const string VERSION_PHASED_INACTIVE = 'INACTIVE';
    protected const string VERSION_PHASED_ACTIVE = 'ACTIVE';
    protected const string VERSION_PHASED_PAUSED = 'PAUSED';
    protected const string VERSION_PHASED_COMPLETE = 'COMPLETE';
    protected const string VERSION_SUBMISSION_READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    protected const string VERSION_SUBMISSION_WAITING_FOR_REVIEW = 'WAITING_FOR_REVIEW';
    protected const string VERSION_SUBMISSION_IN_REVIEW = 'IN_REVIEW';
    protected const string VERSION_SUBMISSION_UNRESOLVED_ISSUES = 'UNRESOLVED_ISSUES';
    protected const string VERSION_SUBMISSION_COMPLETE = 'COMPLETE';
    protected const array VERSION_SUBMISSION_OPEN = [
        self::VERSION_SUBMISSION_READY_FOR_REVIEW,
        self::VERSION_SUBMISSION_WAITING_FOR_REVIEW,
        self::VERSION_SUBMISSION_IN_REVIEW,
        self::VERSION_SUBMISSION_UNRESOLVED_ISSUES,
    ];
    protected const array VERSION_LOCALIZATION_ATTRIBUTES = ['description', 'keywords', 'marketingUrl', 'promotionalText', 'supportUrl', 'whatsNew'];

    protected const string CACHE_IMAGE_LIBRARY = 'mock:distribution:apple:image:library:';
    protected const string CACHE_IMAGE_LIBRARY_APP = 'mock:distribution:apple:image:library-app:';
    protected const string CACHE_IMAGE_IMAGE = 'mock:distribution:apple:image:image:';
    protected const string CACHE_IMAGE_SEEDS = 'mock:distribution:apple:image:seeds:';
    protected const string CACHE_IMAGE_PLACEMENTS = 'mock:distribution:apple:image:placements:';
    protected const string CACHE_IMAGE_PLACEMENT = 'mock:distribution:apple:image:placement:';
    protected const string CACHE_IMAGE_USES = 'mock:distribution:apple:image:uses:';
    protected const string CACHE_INFO_INFOS = 'mock:distribution:apple:info:infos:';
    protected const string CACHE_INFO_INFO = 'mock:distribution:apple:info:info:';
    protected const string CACHE_INFO_LOCALIZATION = 'mock:distribution:apple:info:localization:';
    protected const string IMAGE_TYPE = 'appAssetLibraryImages';
    protected const string IMAGE_TYPE_LIBRARY = 'appAssetLibraries';
    protected const string IMAGE_TYPE_PLACEMENT = 'appAssetLibraryPlacements';
    protected const string IMAGE_TYPE_ORDERING = 'appAssetLibraryPlacementOrderingRequests';
    protected const string IMAGE_AWAITING_UPLOAD = 'AWAITING_UPLOAD';
    protected const string IMAGE_UPLOAD_COMPLETE = 'UPLOAD_COMPLETE';
    protected const string IMAGE_FAILED = 'FAILED';
    protected const string IMAGE_PREPARE_FOR_SUBMISSION = 'PREPARE_FOR_SUBMISSION';
    protected const array IMAGE_OUTCOMES = [self::IMAGE_PREPARE_FOR_SUBMISSION, self::IMAGE_UPLOAD_COMPLETE, self::IMAGE_FAILED];
    protected const array IMAGE_UNPLACEABLE = [self::IMAGE_AWAITING_UPLOAD, self::IMAGE_UPLOAD_COMPLETE, self::IMAGE_FAILED];
    protected const array IMAGE_CATEGORIES = ['CREATIVE_ASSETS', 'APP_SCREENSHOTS_AND_PREVIEWS'];
    protected const array IMAGE_PLACEMENT_TYPES = [
        'APP_SCREENSHOT',
        'IMESSAGE_APP_SCREENSHOT',
        'APP_PREVIEW',
        'PRODUCT_PAGE_HEADER_ASSET',
        'APP_STORE_SEARCH_RESULTS_ASSET',
        'SEARCH_RESULTS_ADS_ASSET',
        'TODAY_TAB_ADS_ASSET',
        'EVENT_CARD_ASSET',
        'EVENT_DETAILS_PAGE_ASSET',
        'RETENTION_MESSAGE_ASSET',
    ];
    protected const string IMAGE_PLACEMENT_STATE = 'PARENT_PREPARE_FOR_SUBMISSION';
    protected const string IMAGE_CODE_HAS_PLACEMENTS = 'STATE_ERROR.ASSET_HAS_PLACEMENTS';
    protected const string INFO_TYPE = 'appInfos';
    protected const string INFO_TYPE_LOCALIZATION = 'appInfoLocalizations';
    protected const string INFO_PREPARE_FOR_SUBMISSION = 'PREPARE_FOR_SUBMISSION';
    protected const array INFO_EDITABLE = [self::INFO_PREPARE_FOR_SUBMISSION, 'DEVELOPER_REJECTED', 'REJECTED'];
    protected const array INFO_LOCALIZATION_ATTRIBUTES = ['name', 'subtitle', 'privacyPolicyUrl', 'privacyChoicesUrl', 'privacyPolicyText'];

    /**
     * @return array{keyId: string, issuerId: string, publicKey: string, agreements: bool}|null
     */
    protected function authorize(Request $request, Response $response, Cache $cache): ?array
    {
        $key = $this->verify($request->getHeaderLine('authorization'), $cache);

        if ($key === null) {
            $this->error(
                $response,
                Response::STATUS_CODE_UNAUTHORIZED,
                'NOT_AUTHORIZED',
                'Authentication credentials are missing or invalid.',
                'Provide a properly configured and signed bearer token, and make sure that it has not expired.',
            );

            return null;
        }

        $remaining = $this->count($cache, $key['keyId']);
        $response->setHeader(self::HEADER_RATE_LIMIT, $this->rate(\max(0, $remaining)));

        if ($remaining < 0) {
            $this->error(
                $response,
                Response::STATUS_CODE_TOO_MANY_REQUESTS,
                'RATE_LIMIT_EXCEEDED',
                'The request rate limit has been reached.',
                "We've received too many requests for this API. Please wait and try again or slow down your request rate.",
            );

            return null;
        }

        if (!$key['agreements']) {
            $this->error(
                $response,
                Response::STATUS_CODE_FORBIDDEN,
                'FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED',
                'A required agreement is missing or has expired.',
                'This request requires an in-effect agreement that has not been signed or has expired.',
            );

            return null;
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function respond(Response $response, array $body, int $status = Response::STATUS_CODE_OK): void
    {
        if (!$response->hasHeader(self::HEADER_RATE_LIMIT)) {
            $response->setHeader(self::HEADER_RATE_LIMIT, $this->rate(self::RATE_LIMIT));
        }

        $response
            ->setStatusCode($status)
            ->json($body);
    }

    /**
     * @param array<string, string> $source
     */
    protected function error(Response $response, int $status, string $code, string $title, string $detail, array $source = []): void
    {
        $error = [
            'id' => $this->uuid(),
            'status' => (string) $status,
            'code' => $code,
            'title' => $title,
            'detail' => $detail,
        ];

        if ($source !== []) {
            $error['source'] = $source;
        }

        $this->respond($response, ['errors' => [$error]], $status);
    }

    protected function link(Request $request): string
    {
        $query = $request->getServer('query_string') ?? '';
        $link = $request->getProtocol() . '://' . $request->getHostname() . $request->getURI();

        return $query === '' ? $link : $link . '?' . $query;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|\stdClass
     */
    protected function select(array $attributes, array $fields, string $type): array|\stdClass
    {
        $selected = $fields[$type] ?? null;

        if (!\is_string($selected) || $selected === '') {
            return $attributes;
        }

        $attributes = \array_intersect_key($attributes, \array_flip(\explode(',', $selected)));

        return $attributes === [] ? new \stdClass() : $attributes;
    }

    protected function owns(Cache $cache, string $issuerId, string $appId): bool
    {
        $owner = $cache->load(self::CACHE_APP . $appId, self::TTL);

        return \is_array($owner) && $owner['issuerId'] === $issuerId;
    }

    protected function notFound(Response $response, string $type, string $id): void
    {
        $this->error(
            $response,
            Response::STATUS_CODE_NOT_FOUND,
            self::CODE_NOT_FOUND,
            'The specified resource does not exist',
            "There is no resource of type '{$type}' with id '{$id}'",
        );
    }

    protected function locked(Lock $lock, string $key, \Closure $callback): mixed
    {
        return $lock->withKey(
            'lock:' . $key,
            $callback,
            target: self::LOCK_TARGET,
            ttl: self::LOCK_TTL,
            waitTimeout: self::LOCK_WAIT,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function build(Cache $cache, string $buildId): ?array
    {
        $appId = $cache->load(self::CACHE_BUILD . $buildId, self::TTL);

        if (!\is_string($appId)) {
            return null;
        }

        foreach ($this->builds($cache, $appId) as $build) {
            if ($build['id'] === $buildId) {
                return $build;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $build
     */
    protected function saveBuild(Cache $cache, Lock $lock, string $appId, array $build): void
    {
        $this->locked($lock, self::CACHE_BUILDS . $appId, function () use ($cache, $appId, $build): void {
            $builds = $this->builds($cache, $appId);
            $index = \array_search($build['id'], \array_column($builds, 'id'), true);

            if ($index === false) {
                $builds[] = $build;
            } else {
                $builds[$index] = $build;
            }

            $cache->save(self::CACHE_BUILDS . $appId, $builds, '', self::TTL);
            $cache->save(self::CACHE_BUILD . $build['id'], $appId, '', self::TTL);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function webhooks(Cache $cache, string $appId): array
    {
        $webhooks = $cache->load(self::CACHE_WEBHOOKS . $appId, self::TTL);

        return \is_array($webhooks) ? \array_values($webhooks) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function webhook(Cache $cache, string $webhookId): ?array
    {
        $appId = $cache->load(self::CACHE_WEBHOOK . $webhookId, self::TTL);

        if (!\is_string($appId)) {
            return null;
        }

        foreach ($this->webhooks($cache, $appId) as $webhook) {
            if ($webhook['id'] === $webhookId) {
                return $webhook;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $webhook
     */
    protected function saveWebhook(Cache $cache, Lock $lock, array $webhook): void
    {
        $this->locked($lock, self::CACHE_WEBHOOKS . $webhook['appId'], function () use ($cache, $webhook): void {
            $webhooks = $this->webhooks($cache, $webhook['appId']);
            $webhooks[] = $webhook;

            $cache->save(self::CACHE_WEBHOOKS . $webhook['appId'], $webhooks, '', self::TTL);
            $cache->save(self::CACHE_WEBHOOK . $webhook['id'], $webhook['appId'], '', self::TTL);
        });
    }

    /**
     * @param array<string, mixed> $webhook
     */
    protected function deleteWebhook(Cache $cache, Lock $lock, array $webhook): void
    {
        $this->locked($lock, self::CACHE_WEBHOOKS . $webhook['appId'], function () use ($cache, $webhook): void {
            $webhooks = \array_values(\array_filter(
                $this->webhooks($cache, $webhook['appId']),
                fn (array $stored): bool => $stored['id'] !== $webhook['id'],
            ));

            $cache->save(self::CACHE_WEBHOOKS . $webhook['appId'], $webhooks, '', self::TTL);
            $cache->purge(self::CACHE_WEBHOOK . $webhook['id']);
            $cache->purge(self::CACHE_DELIVERIES . $webhook['id']);
        });
    }

    /**
     * @param array<string, mixed> $webhook
     * @return array<string, mixed>
     */
    protected function webhookResource(array $webhook): array
    {
        return [
            'type' => 'webhooks',
            'id' => $webhook['id'],
            'attributes' => [
                'enabled' => $webhook['enabled'],
                'eventTypes' => $webhook['eventTypes'],
                'name' => $webhook['name'],
                'url' => $webhook['url'],
            ],
        ];
    }

    /**
     * @param array{attributes?: array<string, mixed>, relationships?: array<string, mixed>} $data
     */
    protected function deliver(Cache $cache, Lock $lock, Client $client, string $appId, string $eventType, array $data): void
    {
        foreach ($this->webhooks($cache, $appId) as $webhook) {
            if ($webhook['enabled'] && \in_array($eventType, $webhook['eventTypes'], true)) {
                $this->post($cache, $lock, $client, $webhook, $eventType, $data);
            }
        }
    }

    /**
     * @param array<string, mixed> $webhook
     * @param array{attributes?: array<string, mixed>, relationships?: array<string, mixed>} $data
     */
    protected function post(Cache $cache, Lock $lock, Client $client, array $webhook, string $eventType, array $data): void
    {
        $this->send($cache, $lock, $client, $webhook, $eventType, ['data' => [
            'type' => \lcfirst(\str_replace('_', '', \ucwords(\strtolower($eventType), '_'))),
            'id' => $this->uuid(),
            'version' => 1,
        ] + $data]);
    }

    /**
     * @param array<string, mixed> $webhook
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function send(Cache $cache, Lock $lock, Client $client, array $webhook, string $eventType, array $payload): array
    {
        $delivery = [
            'id' => $this->uuid(),
            'eventType' => $eventType,
            'createdDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'url' => $webhook['url'],
            'deliveryState' => self::DELIVERY_FAILED,
            'httpStatusCode' => null,
            'errorMessage' => null,
        ];

        try {
            $body = \json_encode($payload, JSON_THROW_ON_ERROR);

            $request = (new Factory())->body(Method::POST, $webhook['url'], $body, ContentType::JSON, [
                self::SIGNATURE_HEADER => self::SIGNATURE_PREFIX . \hash_hmac('sha256', $body, $webhook['secret']),
            ]);

            $status = $client
                ->withTimeout(self::DELIVERY_TIMEOUT)
                ->withConnectTimeout(self::DELIVERY_TIMEOUT)
                ->sendRequest($request)
                ->getStatusCode();

            $delivery['httpStatusCode'] = $status;
            $delivery['deliveryState'] = $status >= 200 && $status < 300 ? self::DELIVERY_SUCCEEDED : self::DELIVERY_FAILED;
        } catch (\Throwable $error) {
            $delivery['errorMessage'] = $error->getMessage();
        }

        $this->locked($lock, self::CACHE_DELIVERIES . $webhook['id'], function () use ($cache, $webhook, $delivery): void {
            $deliveries = $this->deliveries($cache, $webhook['id']);
            $deliveries[] = $delivery;

            $cache->save(self::CACHE_DELIVERIES . $webhook['id'], $deliveries, '', self::TTL);
        });

        return $delivery;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function deliveries(Cache $cache, string $webhookId): array
    {
        $deliveries = $cache->load(self::CACHE_DELIVERIES . $webhookId, self::TTL);

        return \is_array($deliveries) ? \array_values($deliveries) : [];
    }

    protected function uuid(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = \bin2hex($bytes);

        return \implode('-', [
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function builds(Cache $cache, string $appId): array
    {
        $builds = $cache->load(self::CACHE_BUILDS . $appId, self::TTL);

        return \is_array($builds) ? \array_values($builds) : [];
    }

    /**
     * @return array{keyId: string, issuerId: string, publicKey: string, agreements: bool}|null
     */
    private function verify(string $authorization, Cache $cache): ?array
    {
        if (!\str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $parts = \explode('.', \substr($authorization, \strlen('Bearer ')));

        if (\count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $claimsEncoded, $signatureEncoded] = $parts;
        $header = \json_decode($this->decode($headerEncoded) ?? '', true);
        $claims = \json_decode($this->decode($claimsEncoded) ?? '', true);
        $signature = $this->decode($signatureEncoded);

        if (!\is_array($header) || !\is_array($claims) || $signature === null || \strlen($signature) !== self::SIGNATURE_PART * 2) {
            return null;
        }

        $keyId = $header['kid'] ?? null;

        if (($header['alg'] ?? null) !== self::ALGORITHM || !\is_string($keyId) || $keyId === '') {
            return null;
        }

        $key = $cache->load(self::CACHE_KEYS . $keyId, self::TTL);

        if (!\is_array($key)) {
            return null;
        }

        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $now = \time();

        if (
            !\is_int($issuedAt)
            || !\is_int($expiresAt)
            || ($claims['iss'] ?? null) !== $key['issuerId']
            || ($claims['aud'] ?? null) !== self::AUDIENCE
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > self::LIFETIME
            || $expiresAt < $now
            || $issuedAt > $now + self::SKEW
        ) {
            return null;
        }

        $publicKey = \openssl_pkey_get_public($key['publicKey']);

        if ($publicKey === false) {
            return null;
        }

        $verified = \openssl_verify($headerEncoded . '.' . $claimsEncoded, $this->der($signature), $publicKey, OPENSSL_ALGO_SHA256);

        return $verified === 1 ? $key : null;
    }

    private function decode(string $text): ?string
    {
        $padding = \str_repeat('=', (4 - \strlen($text) % 4) % 4);
        $decoded = \base64_decode(\strtr($text, '-_', '+/') . $padding, true);

        return $decoded === false ? null : $decoded;
    }

    private function der(string $raw): string
    {
        $body = $this->integer(\substr($raw, 0, self::SIGNATURE_PART)) . $this->integer(\substr($raw, self::SIGNATURE_PART));

        return "\x30" . \chr(\strlen($body)) . $body;
    }

    private function integer(string $part): string
    {
        $part = \ltrim($part, "\x00");

        if ($part === '' || (\ord($part[0]) & 0x80) !== 0) {
            $part = "\x00" . $part;
        }

        return "\x02" . \chr(\strlen($part)) . $part;
    }

    private function count(Cache $cache, string $keyId): int
    {
        $now = \time();
        $window = $cache->load(self::CACHE_REQUESTS . $keyId, self::RATE_WINDOW);

        if (!\is_array($window) || $now - $window['start'] >= self::RATE_WINDOW) {
            $window = ['start' => $now, 'count' => 0];
        }

        $window['count']++;
        $cache->save(self::CACHE_REQUESTS . $keyId, $window);

        return self::RATE_LIMIT - $window['count'];
    }

    private function rate(int $remaining): string
    {
        return 'user-hour-lim:' . self::RATE_LIMIT . ';user-hour-rem:' . $remaining . ';';
    }

    protected const int PART_SIZE = 1048576;

    /**
     * @return list<array{method: string, url: string, offset: int, length: int, partNumber: int, expiration: string, requestHeaders: list<array{name: string, value: string}>}>
     */
    protected function reserve(Request $request, Cache $cache, string $owner, int $size): array
    {
        $expiration = new \DateTimeImmutable('+' . self::TTL . ' seconds');
        $port = $request->getPort();
        $url = $request->getProtocol() . '://' . $request->getHostname() . ($port === '' ? '' : ':' . $port) . self::PREFIX . '/storage/' . \rawurlencode($owner) . '/parts/';
        $operations = [];

        for ($offset = 0, $partNumber = 1; $offset < $size; $offset += self::PART_SIZE, $partNumber++) {
            $operations[] = [
                'method' => 'PUT',
                'url' => $url . $partNumber,
                'offset' => $offset,
                'length' => \min(self::PART_SIZE, $size - $offset),
                'partNumber' => $partNumber,
                'expiration' => $expiration->format(\DateTimeInterface::RFC3339_EXTENDED),
                'requestHeaders' => [
                    ['name' => 'Content-Type', 'value' => 'application/octet-stream'],
                ],
            ];
        }

        $cache->save(self::CACHE_UPLOAD_RESERVATIONS . $owner, [
            'operations' => $operations,
            'expiration' => $expiration->getTimestamp(),
            'sealed' => false,
        ], '', self::TTL);

        return $operations;
    }

    /**
     * @return array{operations: list<array<string, mixed>>, expiration: int, sealed: bool}|null
     */
    protected function reservation(Cache $cache, string $reservationId): ?array
    {
        $reservation = $cache->load(self::CACHE_UPLOAD_RESERVATIONS . $reservationId, self::TTL);

        return \is_array($reservation) ? $reservation : null;
    }

    protected function uploaded(Cache $cache, string $reservationId): ?string
    {
        $reservation = $this->reservation($cache, $reservationId);

        if ($reservation === null || $reservation['sealed']) {
            return null;
        }

        $context = \hash_init('sha256');

        foreach ($reservation['operations'] as $operation) {
            $part = $cache->load(self::CACHE_UPLOAD_PARTS . $reservationId . ':' . $operation['partNumber'], self::TTL);

            if (!\is_string($part)) {
                return null;
            }

            \hash_update($context, \base64_decode($part));
        }

        return \hash_final($context);
    }

    protected function seal(Cache $cache, string $reservationId): void
    {
        $reservation = $this->reservation($cache, $reservationId);

        if ($reservation === null) {
            return;
        }

        foreach ($reservation['operations'] as $operation) {
            $cache->purge(self::CACHE_UPLOAD_PARTS . $reservationId . ':' . $operation['partNumber']);
        }

        $cache->save(self::CACHE_UPLOAD_RESERVATIONS . $reservationId, [...$reservation, 'sealed' => true], '', self::TTL);
    }

    /**
     * @param array<string, mixed> $upload
     * @return array<string, mixed>
     */
    protected function buildUploadResource(array $upload): array
    {
        return [
            'type' => 'buildUploads',
            'id' => $upload['id'],
            'attributes' => [
                'cfBundleShortVersionString' => $upload['cfBundleShortVersionString'],
                'cfBundleVersion' => $upload['cfBundleVersion'],
                'platform' => $upload['platform'],
                'createdDate' => $upload['createdDate'],
                'uploadedDate' => $upload['uploadedDate'],
                'state' => [
                    'state' => $upload['state'],
                    'errors' => $upload['errors'],
                    'warnings' => [],
                    'infos' => [],
                ],
            ],
            'relationships' => [
                'build' => ['data' => $upload['buildId'] === null ? null : ['type' => 'builds', 'id' => $upload['buildId']]],
                'assetFile' => ['data' => $upload['fileId'] === null ? null : ['type' => 'buildUploadFiles', 'id' => $upload['fileId']]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    protected function buildUploadFileResource(array $file): array
    {
        return [
            'type' => 'buildUploadFiles',
            'id' => $file['id'],
            'attributes' => [
                'assetDeliveryState' => [
                    'state' => $file['state'],
                    'errors' => [],
                    'warnings' => [],
                ],
                'assetToken' => $file['assetToken'],
                'assetType' => $file['assetType'],
                'fileName' => $file['fileName'],
                'fileSize' => $file['fileSize'],
                'sourceFileChecksums' => $file['sourceFileChecksums'],
                'uti' => $file['uti'],
                'uploadOperations' => $file['uploadOperations'],
            ],
            'relationships' => [
                'buildUpload' => ['data' => ['type' => 'buildUploads', 'id' => $file['buildUploadId']]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $build
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function exposeBuild(array $build, array $fields): array
    {
        unset($build[self::INTERNAL]);
        $build['attributes'] = $this->select($build['attributes'], $fields, 'builds');

        return $build;
    }

    /**
     * @return array{appId: string, build: array<string, mixed>}|null
     */
    protected function betaBuild(Cache $cache, string $issuerId, string $buildId): ?array
    {
        $appId = $cache->load(self::CACHE_BUILD . $buildId, self::TTL);

        if (!\is_string($appId) || !$this->owns($cache, $issuerId, $appId)) {
            return null;
        }

        $build = $this->build($cache, $buildId);

        return $build === null ? null : ['appId' => $appId, 'build' => $build];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function betaBuildByVersion(Cache $cache, string $appId, string $version): ?array
    {
        foreach ($this->builds($cache, $appId) as $build) {
            if (($build['attributes']['version'] ?? null) === $version) {
                return $build;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $build
     * @return array{id: string, internalBuildState: string, externalBuildState: string}
     */
    protected function betaDetail(Cache $cache, Lock $lock, Client $client, string $appId, array $build): array
    {
        [$before, $after] = $this->locked($lock, self::CACHE_BETA_DETAIL . $build['id'], function () use ($cache, $appId, $build): array {
            $key = self::CACHE_BETA_REVIEW . $appId . ':' . $build['attributes']['version'];
            $review = $cache->load($key, self::TTL);

            if (!\is_array($review)) {
                $detail = $this->betaResolve($build, $this->betaStored($cache, $build['id']));

                return [$detail, $detail];
            }

            $cache->purge($key);

            return $this->betaChange($cache, $build, $review['state'], $review['internalState']);
        });

        $this->betaDeliver($cache, $lock, $client, $appId, $before, $after);

        return $after;
    }

    /**
     * @param array<string, mixed> $build
     * @return array{id: string, internalBuildState: string, externalBuildState: string}
     */
    protected function updateBetaDetail(Cache $cache, Lock $lock, Client $client, string $appId, array $build, ?string $externalState, ?string $internalState): array
    {
        [$before, $after] = $this->locked(
            $lock,
            self::CACHE_BETA_DETAIL . $build['id'],
            fn (): array => $this->betaChange($cache, $build, $externalState, $internalState),
        );

        $this->betaDeliver($cache, $lock, $client, $appId, $before, $after);

        return $after;
    }

    /**
     * @param array{id: string, internalBuildState: string, externalBuildState: string} $before
     * @param array{id: string, internalBuildState: string, externalBuildState: string} $after
     */
    protected function betaDeliver(Cache $cache, Lock $lock, Client $client, string $appId, array $before, array $after): void
    {
        if ($before['externalBuildState'] === $after['externalBuildState']) {
            return;
        }

        $this->deliver($cache, $lock, $client, $appId, self::BETA_EVENT, [
            'attributes' => [
                'newExternalBuildState' => $after['externalBuildState'],
                'oldExternalBuildState' => $before['externalBuildState'],
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            ],
            'relationships' => [
                'instance' => ['data' => ['type' => 'buildBetaDetails', 'id' => $after['id']]],
            ],
        ]);
    }

    /**
     * @param array{id: string, internalBuildState: string, externalBuildState: string} $detail
     * @return array<string, mixed>
     */
    protected function betaDetailResource(array $detail): array
    {
        return [
            'type' => 'buildBetaDetails',
            'id' => $detail['id'],
            'attributes' => [
                'autoNotifyEnabled' => true,
                'internalBuildState' => $detail['internalBuildState'],
                'externalBuildState' => $detail['externalBuildState'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function betaSubmission(Cache $cache, string $buildId): ?array
    {
        $submission = $cache->load(self::CACHE_BETA_SUBMISSION . $buildId, self::TTL);

        return \is_array($submission) ? $submission : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function betaLocalizations(Cache $cache, string $buildId): array
    {
        $localizations = $cache->load(self::CACHE_BETA_LOCALIZATIONS . $buildId, self::TTL);

        return \is_array($localizations) ? \array_values($localizations) : [];
    }

    /**
     * @param array<string, mixed> $localization
     */
    protected function saveBetaLocalization(Cache $cache, Lock $lock, string $buildId, array $localization): void
    {
        $this->locked($lock, self::CACHE_BETA_LOCALIZATIONS . $buildId, function () use ($cache, $buildId, $localization): void {
            $localizations = $this->betaLocalizations($cache, $buildId);
            $index = \array_search($localization['id'], \array_column($localizations, 'id'), true);

            if ($index === false) {
                $localizations[] = $localization;
            } else {
                $localizations[$index] = $localization;
            }

            $cache->save(self::CACHE_BETA_LOCALIZATIONS . $buildId, $localizations, '', self::TTL);
            $cache->save(self::CACHE_BETA_LOCALIZATION . $localization['id'], $buildId, '', self::TTL);
        });
    }

    /**
     * @return array{buildId: string, localization: array<string, mixed>}|null
     */
    protected function betaLocalization(Cache $cache, string $localizationId): ?array
    {
        $buildId = $cache->load(self::CACHE_BETA_LOCALIZATION . $localizationId, self::TTL);

        if (!\is_string($buildId)) {
            return null;
        }

        foreach ($this->betaLocalizations($cache, $buildId) as $localization) {
            if ($localization['id'] === $localizationId) {
                return ['buildId' => $buildId, 'localization' => $localization];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function betaGroups(Cache $cache, string $appId): array
    {
        $groups = $cache->load(self::CACHE_BETA_GROUPS . $appId, self::TTL);

        return \is_array($groups) ? \array_values($groups) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function betaGroup(Cache $cache, string $appId, string $groupId): ?array
    {
        if ($cache->load(self::CACHE_BETA_GROUP . $groupId, self::TTL) !== $appId) {
            return null;
        }

        foreach ($this->betaGroups($cache, $appId) as $group) {
            if ($group['id'] === $groupId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $group
     */
    protected function saveBetaGroup(Cache $cache, Lock $lock, string $appId, array $group): void
    {
        $this->locked($lock, self::CACHE_BETA_GROUPS . $appId, function () use ($cache, $appId, $group): void {
            $groups = $this->betaGroups($cache, $appId);
            $index = \array_search($group['id'], \array_column($groups, 'id'), true);

            if ($index === false) {
                $groups[] = $group;
            } else {
                $groups[$index] = $group;
            }

            $cache->save(self::CACHE_BETA_GROUPS . $appId, $groups, '', self::TTL);
            $cache->save(self::CACHE_BETA_GROUP . $group['id'], $appId, '', self::TTL);
        });
    }

    /**
     * @return array{id: string, internalBuildState: ?string, externalBuildState: ?string}
     */
    private function betaStored(Cache $cache, string $buildId): array
    {
        $stored = $cache->load(self::CACHE_BETA_DETAIL . $buildId, self::TTL);

        if (\is_array($stored)) {
            return $stored;
        }

        $stored = ['id' => $this->uuid(), 'internalBuildState' => null, 'externalBuildState' => null];
        $cache->save(self::CACHE_BETA_DETAIL . $buildId, $stored, '', self::TTL);

        return $stored;
    }

    /**
     * @param array<string, mixed> $build
     * @return array{0: array{id: string, internalBuildState: string, externalBuildState: string}, 1: array{id: string, internalBuildState: string, externalBuildState: string}}
     */
    private function betaChange(Cache $cache, array $build, ?string $externalState, ?string $internalState): array
    {
        $stored = $this->betaStored($cache, $build['id']);
        $before = $this->betaResolve($build, $stored);

        $stored['externalBuildState'] = $externalState ?? $stored['externalBuildState'];
        $stored['internalBuildState'] = $internalState ?? $stored['internalBuildState'];
        $cache->save(self::CACHE_BETA_DETAIL . $build['id'], $stored, '', self::TTL);

        $after = $this->betaResolve($build, $stored);
        $submission = $this->betaSubmission($cache, $build['id']);
        $reviewState = self::BETA_REVIEW_STATES[$after['externalBuildState']] ?? null;

        if ($submission !== null && $reviewState !== null) {
            $submission['attributes']['betaReviewState'] = $reviewState;
            $cache->save(self::CACHE_BETA_SUBMISSION . $build['id'], $submission, '', self::TTL);
        }

        return [$before, $after];
    }

    /**
     * @param array<string, mixed> $build
     * @param array{id: string, internalBuildState: ?string, externalBuildState: ?string} $stored
     * @return array{id: string, internalBuildState: string, externalBuildState: string}
     */
    private function betaResolve(array $build, array $stored): array
    {
        $attributes = $build['attributes'];
        $internalOnly = ($attributes['buildAudienceType'] ?? null) === self::BETA_INTERNAL_ONLY;

        $state = match (true) {
            ($attributes['expired'] ?? false) === true => 'EXPIRED',
            ($attributes['processingState'] ?? 'VALID') === 'PROCESSING' => 'PROCESSING',
            \in_array($attributes['processingState'] ?? 'VALID', ['FAILED', 'INVALID'], true) => 'PROCESSING_EXCEPTION',
            default => null,
        };

        if ($state !== null) {
            return ['id' => $stored['id'], 'internalBuildState' => $state, 'externalBuildState' => $internalOnly ? 'NOT_APPLICABLE' : $state];
        }

        $compliance = ($attributes['usesNonExemptEncryption'] ?? null) === false ? null : 'MISSING_EXPORT_COMPLIANCE';

        return [
            'id' => $stored['id'],
            'internalBuildState' => $stored['internalBuildState'] ?? $compliance ?? 'READY_FOR_BETA_TESTING',
            'externalBuildState' => $internalOnly ? 'NOT_APPLICABLE' : ($stored['externalBuildState'] ?? $compliance ?? self::BETA_READY_FOR_SUBMISSION),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function versions(Cache $cache, string $appId): array
    {
        $versions = $cache->load(self::CACHE_VERSION_VERSIONS . $appId, self::TTL);

        return \is_array($versions) ? \array_values($versions) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function version(Cache $cache, string $versionId): ?array
    {
        $appId = $cache->load(self::CACHE_VERSION_VERSION . $versionId, self::TTL);

        if (!\is_string($appId)) {
            return null;
        }

        foreach ($this->versions($cache, $appId) as $version) {
            if ($version['id'] === $versionId) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $version
     */
    protected function saveVersion(Cache $cache, Lock $lock, array $version): void
    {
        $appId = $version[self::INTERNAL]['appId'];

        $this->locked($lock, self::CACHE_VERSION_VERSIONS . $appId, function () use ($cache, $appId, $version): void {
            $versions = $this->versions($cache, $appId);
            $index = \array_search($version['id'], \array_column($versions, 'id'), true);

            if ($index === false) {
                $versions[] = $version;
            } else {
                $versions[$index] = $version;
            }

            $cache->save(self::CACHE_VERSION_VERSIONS . $appId, $versions, '', self::TTL);
            $cache->save(self::CACHE_VERSION_VERSION . $version['id'], $appId, '', self::TTL);

            foreach ($version[self::INTERNAL]['localizations'] as $localization) {
                $cache->save(self::CACHE_VERSION_LOCALIZATION . $localization['id'], $version['id'], '', self::TTL);
            }

            if ($version[self::INTERNAL]['phased'] !== null) {
                $cache->save(self::CACHE_VERSION_PHASED . $version[self::INTERNAL]['phased']['id'], $version['id'], '', self::TTL);
            }
        });
    }

    /**
     * @param array<string, mixed>|null $version
     */
    protected function ownsVersion(Cache $cache, string $issuerId, ?array $version): bool
    {
        return $version !== null && $this->owns($cache, $issuerId, $version[self::INTERNAL]['appId']);
    }

    protected function versionReleased(Cache $cache, string $appId): bool
    {
        foreach ($this->versions($cache, $appId) as $version) {
            if (\in_array($version['attributes']['appVersionState'], self::VERSION_RELEASED, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $version
     * @param list<string> $include
     * @return array<string, mixed>
     */
    protected function versionResource(array $version, array $include = []): array
    {
        $relationships = [];

        foreach (['app', 'build', 'appStoreVersionPhasedRelease', 'appStoreVersionLocalizations'] as $name) {
            $relationships[$name] = ['links' => [
                'self' => self::PREFIX . '/v1/appStoreVersions/' . $version['id'] . '/relationships/' . $name,
                'related' => self::PREFIX . '/v1/appStoreVersions/' . $version['id'] . '/' . $name,
            ]];
        }

        $buildId = $version[self::INTERNAL]['buildId'];
        $phased = $version[self::INTERNAL]['phased'];

        if (\in_array('build', $include, true)) {
            $relationships['build']['data'] = $buildId === null ? null : ['type' => 'builds', 'id' => $buildId];
        }

        if (\in_array('appStoreVersionPhasedRelease', $include, true)) {
            $relationships['appStoreVersionPhasedRelease']['data'] = $phased === null ? null : ['type' => self::VERSION_TYPE_PHASED, 'id' => $phased['id']];
        }

        return [
            'type' => self::VERSION_TYPE,
            'id' => $version['id'],
            'attributes' => $version['attributes'],
            'relationships' => $relationships,
            'links' => ['self' => self::PREFIX . '/v1/appStoreVersions/' . $version['id']],
        ];
    }

    /**
     * @param list<array<string, mixed>> $versions
     * @param list<string> $include
     * @return list<array<string, mixed>>
     */
    protected function versionIncluded(Cache $cache, array $versions, array $include): array
    {
        $included = [];

        foreach ($versions as $version) {
            $buildId = $version[self::INTERNAL]['buildId'];
            $build = \in_array('build', $include, true) && $buildId !== null ? $this->build($cache, $buildId) : null;

            if ($build !== null) {
                unset($build[self::INTERNAL]);
                $included[] = $build;
            }

            $phased = $version[self::INTERNAL]['phased'];

            if (\in_array('appStoreVersionPhasedRelease', $include, true) && $phased !== null) {
                $included[] = $phased;
            }
        }

        return $included;
    }

    /**
     * @return list<string>
     */
    protected function versionInclude(string $include): array
    {
        return \array_values(\array_filter(\array_map(\trim(...), \explode(',', $include)), fn (string $name): bool => $name !== ''));
    }

    /**
     * @param array<string, mixed> $relationships
     */
    protected function versionLinkage(array $relationships, string $name, string $type): ?string
    {
        $data = $relationships[$name]['data'] ?? null;

        if (!\is_array($data) || ($data['type'] ?? null) !== $type || !\is_string($data['id'] ?? null) || $data['id'] === '') {
            return null;
        }

        return $data['id'];
    }

    protected function versionConflict(Response $response, string $code, string $title, string $detail, string $pointer = ''): void
    {
        $this->error($response, Response::STATUS_CODE_CONFLICT, $code, $title, $detail, $pointer === '' ? [] : ['pointer' => $pointer]);
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>
     */
    protected function versionTransition(Cache $cache, Lock $lock, Client $client, array $version, string $state): array
    {
        $previous = $version['attributes']['appVersionState'];
        $version['attributes']['appVersionState'] = $state;
        $version['attributes']['appStoreState'] = $state;
        $phased = $version[self::INTERNAL]['phased'];
        $day = $version[self::INTERNAL]['phasedDay'];

        if ($state === self::VERSION_READY_FOR_DISTRIBUTION && $phased !== null) {
            if ($phased['attributes']['phasedReleaseState'] === self::VERSION_PHASED_INACTIVE) {
                $phased['attributes']['phasedReleaseState'] = self::VERSION_PHASED_ACTIVE;
                $phased['attributes']['startDate'] = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED);
                $phased['attributes']['currentDayNumber'] = $day ?? 1;
            } elseif ($day !== null && $phased['attributes']['phasedReleaseState'] !== self::VERSION_PHASED_COMPLETE) {
                $phased['attributes']['currentDayNumber'] = $day;
            }

            $version[self::INTERNAL]['phased'] = $phased;
        }

        $this->saveVersion($cache, $lock, $version);
        $this->versionSubmissionFollow($cache, $lock, $version);

        if ($state === self::VERSION_READY_FOR_DISTRIBUTION) {
            foreach ($this->versions($cache, $version[self::INTERNAL]['appId']) as $live) {
                if ($live['id'] !== $version['id'] && $live['attributes']['appVersionState'] === self::VERSION_READY_FOR_DISTRIBUTION) {
                    $this->versionTransition($cache, $lock, $client, $live, self::VERSION_REPLACED_WITH_NEW_VERSION);
                }
            }
        }

        if ($previous !== $state) {
            $this->deliver($cache, $lock, $client, $version[self::INTERNAL]['appId'], self::VERSION_EVENT, [
                'attributes' => [
                    'newValue' => $state,
                    'oldValue' => $previous,
                    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
                ],
                'relationships' => [
                    'instance' => ['data' => ['type' => self::VERSION_TYPE, 'id' => $version['id']]],
                ],
            ]);
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $localization
     * @return array<string, mixed>
     */
    protected function versionLocalizationResource(array $localization): array
    {
        return [
            'type' => self::VERSION_TYPE_LOCALIZATION,
            'id' => $localization['id'],
            'attributes' => $localization['attributes'],
            'links' => ['self' => self::PREFIX . '/v1/appStoreVersionLocalizations/' . $localization['id']],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function versionOfLocalization(Cache $cache, string $localizationId): ?array
    {
        $versionId = $cache->load(self::CACHE_VERSION_LOCALIZATION . $localizationId, self::TTL);

        return \is_string($versionId) ? $this->version($cache, $versionId) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function versionOfPhasedRelease(Cache $cache, string $phasedReleaseId): ?array
    {
        $versionId = $cache->load(self::CACHE_VERSION_PHASED . $phasedReleaseId, self::TTL);
        $version = \is_string($versionId) ? $this->version($cache, $versionId) : null;

        return $version !== null && ($version[self::INTERNAL]['phased']['id'] ?? null) === $phasedReleaseId ? $version : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function versionSubmissions(Cache $cache, string $appId): array
    {
        $submissions = $cache->load(self::CACHE_VERSION_SUBMISSIONS . $appId, self::TTL);

        return \is_array($submissions) ? \array_values($submissions) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function versionSubmission(Cache $cache, string $submissionId): ?array
    {
        $appId = $cache->load(self::CACHE_VERSION_SUBMISSION . $submissionId, self::TTL);

        if (!\is_string($appId)) {
            return null;
        }

        foreach ($this->versionSubmissions($cache, $appId) as $submission) {
            if ($submission['id'] === $submissionId) {
                return $submission;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $submission
     */
    protected function saveVersionSubmission(Cache $cache, Lock $lock, array $submission): void
    {
        $appId = $submission[self::INTERNAL]['appId'];

        $this->locked($lock, self::CACHE_VERSION_SUBMISSIONS . $appId, function () use ($cache, $appId, $submission): void {
            $submissions = $this->versionSubmissions($cache, $appId);
            $index = \array_search($submission['id'], \array_column($submissions, 'id'), true);

            if ($index === false) {
                $submissions[] = $submission;
            } else {
                $submissions[$index] = $submission;
            }

            $cache->save(self::CACHE_VERSION_SUBMISSIONS . $appId, $submissions, '', self::TTL);
            $cache->save(self::CACHE_VERSION_SUBMISSION . $submission['id'], $appId, '', self::TTL);
        });
    }

    /**
     * @param array<string, mixed> $submission
     * @param list<string> $include
     * @return array<string, mixed>
     */
    protected function versionSubmissionResource(array $submission, array $include = []): array
    {
        $relationships = [];

        foreach (['app', 'items', 'appStoreVersionForReview'] as $name) {
            $relationships[$name] = ['links' => [
                'self' => self::PREFIX . '/v1/reviewSubmissions/' . $submission['id'] . '/relationships/' . $name,
                'related' => self::PREFIX . '/v1/reviewSubmissions/' . $submission['id'] . '/' . $name,
            ]];
        }

        $versionId = $submission[self::INTERNAL]['versionId'];

        if (\in_array('items', $include, true)) {
            $relationships['items']['data'] = \array_map(
                fn (array $item): array => ['type' => self::VERSION_TYPE_ITEM, 'id' => $item['id']],
                $submission[self::INTERNAL]['items'],
            );
        }

        if (\in_array('appStoreVersionForReview', $include, true)) {
            $relationships['appStoreVersionForReview']['data'] = $versionId === null ? null : ['type' => self::VERSION_TYPE, 'id' => $versionId];
        }

        return [
            'type' => self::VERSION_TYPE_SUBMISSION,
            'id' => $submission['id'],
            'attributes' => $submission['attributes'],
            'relationships' => $relationships,
            'links' => ['self' => self::PREFIX . '/v1/reviewSubmissions/' . $submission['id']],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function versionItemResource(array $item): array
    {
        return [
            'type' => self::VERSION_TYPE_ITEM,
            'id' => $item['id'],
            'attributes' => $item['attributes'],
            'relationships' => [
                'appStoreVersion' => ['data' => ['type' => self::VERSION_TYPE, 'id' => $item['versionId']]],
            ],
            'links' => ['self' => self::PREFIX . '/v1/reviewSubmissionItems/' . $item['id']],
        ];
    }

    /**
     * @return array{state: string, phasedDay: int|null}|null
     */
    protected function versionReview(Cache $cache, string $appId, string $versionString): ?array
    {
        $reviews = $cache->load(self::CACHE_VERSION_REVIEWS . $appId, self::TTL);

        return \is_array($reviews) && \is_array($reviews[$versionString] ?? null) ? $reviews[$versionString] : null;
    }

    /**
     * @param array{state: string, phasedDay: int|null}|null $review
     */
    protected function saveVersionReview(Cache $cache, Lock $lock, string $appId, string $versionString, ?array $review): void
    {
        $this->locked($lock, self::CACHE_VERSION_REVIEWS . $appId, function () use ($cache, $appId, $versionString, $review): void {
            $reviews = $cache->load(self::CACHE_VERSION_REVIEWS . $appId, self::TTL);
            $reviews = \is_array($reviews) ? $reviews : [];

            if ($review === null) {
                unset($reviews[$versionString]);
            } else {
                $reviews[$versionString] = $review;
            }

            $cache->save(self::CACHE_VERSION_REVIEWS . $appId, $reviews, '', self::TTL);
        });
    }

    /**
     * @param array<string, mixed> $version
     */
    private function versionSubmissionFollow(Cache $cache, Lock $lock, array $version): void
    {
        $state = match ($version['attributes']['appVersionState']) {
            self::VERSION_READY_FOR_REVIEW => self::VERSION_SUBMISSION_READY_FOR_REVIEW,
            self::VERSION_WAITING_FOR_REVIEW, 'WAITING_FOR_EXPORT_COMPLIANCE' => self::VERSION_SUBMISSION_WAITING_FOR_REVIEW,
            'IN_REVIEW', 'ACCEPTED' => self::VERSION_SUBMISSION_IN_REVIEW,
            'REJECTED', 'METADATA_REJECTED', 'INVALID_BINARY' => self::VERSION_SUBMISSION_UNRESOLVED_ISSUES,
            default => self::VERSION_SUBMISSION_COMPLETE,
        };

        foreach ($this->versionSubmissions($cache, $version[self::INTERNAL]['appId']) as $submission) {
            if ($submission[self::INTERNAL]['versionId'] === $version['id'] && $submission['attributes']['state'] !== self::VERSION_SUBMISSION_COMPLETE) {
                $submission['attributes']['state'] = $state;
                $this->saveVersionSubmission($cache, $lock, $submission);
            }
        }
    }

    protected function imageLibrary(Cache $cache, Lock $lock, string $appId): string
    {
        return $this->locked($lock, self::CACHE_IMAGE_LIBRARY . $appId, function () use ($cache, $appId): string {
            $libraryId = $cache->load(self::CACHE_IMAGE_LIBRARY . $appId, self::TTL);

            if (\is_string($libraryId)) {
                return $libraryId;
            }

            $libraryId = $this->uuid();

            $cache->save(self::CACHE_IMAGE_LIBRARY . $appId, $libraryId, '', self::TTL);
            $cache->save(self::CACHE_IMAGE_LIBRARY_APP . $libraryId, $appId, '', self::TTL);

            return $libraryId;
        });
    }

    protected function imageLibraryApp(Cache $cache, string $libraryId): ?string
    {
        $appId = $cache->load(self::CACHE_IMAGE_LIBRARY_APP . $libraryId, self::TTL);

        return \is_string($appId) ? $appId : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function image(Cache $cache, string $imageId): ?array
    {
        $image = $cache->load(self::CACHE_IMAGE_IMAGE . $imageId, self::TTL);

        return \is_array($image) ? $image : null;
    }

    /**
     * @param array<string, mixed> $image
     */
    protected function saveImage(Cache $cache, array $image): void
    {
        $cache->save(self::CACHE_IMAGE_IMAGE . $image['id'], $image, '', self::TTL);
    }

    /**
     * @param array<string, mixed> $image
     */
    protected function ownsImage(Cache $cache, string $issuerId, ?array $image): bool
    {
        return $image !== null && $this->owns($cache, $issuerId, $image[self::INTERNAL]['appId']);
    }

    /**
     * @param array<string, mixed> $image
     * @return array<string, mixed>
     */
    protected function imageSettle(Cache $cache, array $image): array
    {
        $seed = $this->imageSeed($cache, $image[self::INTERNAL]['appId'], $image['attributes']['referenceName'] ?? '');
        $image['attributes']['state'] = $seed['state'] ?? self::IMAGE_PREPARE_FOR_SUBMISSION;
        $image['attributes']['stateDetails'] = $seed['stateDetails'] ?? [];

        $this->saveImage($cache, $image);

        return $image;
    }

    /**
     * @return array{state: string, stateDetails: list<array{code: string, description: string}>}|null
     */
    protected function imageSeed(Cache $cache, string $appId, string $referenceName): ?array
    {
        $seed = $cache->load(self::CACHE_IMAGE_SEEDS . $appId . ':' . $referenceName, self::TTL);

        return \is_array($seed) ? $seed : null;
    }

    /**
     * @param array<string, mixed> $image
     * @return array<string, mixed>
     */
    protected function imageResource(array $image): array
    {
        return [
            'type' => self::IMAGE_TYPE,
            'id' => $image['id'],
            'attributes' => $image['attributes'],
            'relationships' => [
                'assetLibrary' => ['data' => ['type' => self::IMAGE_TYPE_LIBRARY, 'id' => $image[self::INTERNAL]['libraryId']]],
            ],
            'links' => ['self' => self::PREFIX . '/v1/appAssetLibraryImages/' . $image['id']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function imagePlacements(Cache $cache, string $localizationId): array
    {
        $placements = $cache->load(self::CACHE_IMAGE_PLACEMENTS . $localizationId, self::TTL);

        return \is_array($placements) ? \array_values($placements) : [];
    }

    /**
     * @param list<array<string, mixed>> $placements
     */
    protected function saveImagePlacements(Cache $cache, string $localizationId, array $placements): void
    {
        $cache->save(self::CACHE_IMAGE_PLACEMENTS . $localizationId, \array_values($placements), '', self::TTL);

        foreach ($placements as $placement) {
            $cache->save(self::CACHE_IMAGE_PLACEMENT . $placement['id'], $localizationId, '', self::TTL);
        }
    }

    /**
     * @return list<string>
     */
    protected function imageUses(Cache $cache, string $imageId): array
    {
        $uses = $cache->load(self::CACHE_IMAGE_USES . $imageId, self::TTL);

        return \is_array($uses) ? \array_values($uses) : [];
    }

    /**
     * @param list<string> $uses
     */
    protected function saveImageUses(Cache $cache, string $imageId, array $uses): void
    {
        $cache->save(self::CACHE_IMAGE_USES . $imageId, \array_values($uses), '', self::TTL);
    }

    /**
     * @param array<string, mixed> $placement
     * @return array<string, mixed>
     */
    protected function imagePlacementResource(array $placement, bool $linkage): array
    {
        $image = ['links' => ['related' => self::PREFIX . '/v1/appAssetLibraryPlacements/' . $placement['id'] . '/image']];

        if ($linkage) {
            $image['data'] = ['type' => self::IMAGE_TYPE, 'id' => $placement[self::INTERNAL]['imageId']];
        }

        return [
            'type' => self::IMAGE_TYPE_PLACEMENT,
            'id' => $placement['id'],
            'attributes' => $placement['attributes'],
            'relationships' => ['image' => $image],
            'links' => ['self' => self::PREFIX . '/v1/appAssetLibraryPlacements/' . $placement['id']],
        ];
    }

    /**
     * @param array<string, mixed> $version
     */
    protected function imageLocked(Response $response, array $version): bool
    {
        $state = $version['attributes']['appVersionState'];

        if (\in_array($state, self::VERSION_EDITABLE, true)) {
            return false;
        }

        $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The version is {$state} and its placements cannot be changed.");

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function infos(Cache $cache, Lock $lock, string $issuerId, string $appId): array
    {
        return $this->locked($lock, self::CACHE_INFO_INFOS . $appId, function () use ($cache, $issuerId, $appId): array {
            $infos = $cache->load(self::CACHE_INFO_INFOS . $appId, self::TTL);

            if (\is_array($infos) && $infos !== []) {
                return \array_values($infos);
            }

            $apps = $cache->load(self::CACHE_APPS . $issuerId, self::TTL);
            $app = null;

            foreach (\is_array($apps) ? $apps : [] as $candidate) {
                if ($candidate['id'] === $appId) {
                    $app = $candidate;
                }
            }

            $info = [
                'type' => self::INFO_TYPE,
                'id' => $this->uuid(),
                'attributes' => [
                    'appStoreAgeRating' => null,
                    'appStoreState' => self::INFO_PREPARE_FOR_SUBMISSION,
                    'state' => self::INFO_PREPARE_FOR_SUBMISSION,
                    'kidsAgeBand' => null,
                ],
                self::INTERNAL => [
                    'appId' => $appId,
                    'localizations' => [[
                        'id' => $this->uuid(),
                        'attributes' => [
                            'locale' => $app['attributes']['primaryLocale'] ?? 'en-US',
                            'name' => $app['attributes']['name'] ?? null,
                            'subtitle' => null,
                            'privacyPolicyUrl' => null,
                            'privacyChoicesUrl' => null,
                            'privacyPolicyText' => null,
                        ],
                    ]],
                ],
            ];

            $this->storeInfo($cache, $info);

            return [$info];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function info(Cache $cache, string $appInfoId): ?array
    {
        $appId = $cache->load(self::CACHE_INFO_INFO . $appInfoId, self::TTL);
        $infos = \is_string($appId) ? $cache->load(self::CACHE_INFO_INFOS . $appId, self::TTL) : null;

        foreach (\is_array($infos) ? $infos : [] as $info) {
            if ($info['id'] === $appInfoId) {
                return $info;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $info
     */
    protected function saveInfo(Cache $cache, Lock $lock, array $info): void
    {
        $this->locked($lock, self::CACHE_INFO_INFOS . $info[self::INTERNAL]['appId'], fn () => $this->storeInfo($cache, $info));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function infoOfLocalization(Cache $cache, string $localizationId): ?array
    {
        $appInfoId = $cache->load(self::CACHE_INFO_LOCALIZATION . $localizationId, self::TTL);

        return \is_string($appInfoId) ? $this->info($cache, $appInfoId) : null;
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    protected function infoResource(array $info): array
    {
        return [
            'type' => self::INFO_TYPE,
            'id' => $info['id'],
            'attributes' => $info['attributes'],
            'relationships' => [
                'appInfoLocalizations' => ['links' => [
                    'self' => self::PREFIX . '/v1/appInfos/' . $info['id'] . '/relationships/appInfoLocalizations',
                    'related' => self::PREFIX . '/v1/appInfos/' . $info['id'] . '/appInfoLocalizations',
                ]],
            ],
            'links' => ['self' => self::PREFIX . '/v1/appInfos/' . $info['id']],
        ];
    }

    /**
     * @param array<string, mixed> $localization
     * @return array<string, mixed>
     */
    protected function infoLocalizationResource(array $localization): array
    {
        return [
            'type' => self::INFO_TYPE_LOCALIZATION,
            'id' => $localization['id'],
            'attributes' => $localization['attributes'],
            'links' => ['self' => self::PREFIX . '/v1/appInfoLocalizations/' . $localization['id']],
        ];
    }

    /**
     * @param array<string, mixed> $info
     */
    protected function infoLocked(Response $response, array $info): bool
    {
        $state = $info['attributes']['state'];

        if (\in_array($state, self::INFO_EDITABLE, true)) {
            return false;
        }

        $this->versionConflict($response, self::VERSION_CODE_STATE, 'The request cannot be fulfilled because of the state of another resource.', "The app information is {$state} and its localizations cannot be edited.");

        return true;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function storeInfo(Cache $cache, array $info): void
    {
        $appId = $info[self::INTERNAL]['appId'];
        $infos = $cache->load(self::CACHE_INFO_INFOS . $appId, self::TTL);
        $infos = \is_array($infos) ? \array_values($infos) : [];
        $index = \array_search($info['id'], \array_column($infos, 'id'), true);

        if ($index === false) {
            $infos[] = $info;
        } else {
            $infos[$index] = $info;
        }

        $cache->save(self::CACHE_INFO_INFOS . $appId, $infos, '', self::TTL);
        $cache->save(self::CACHE_INFO_INFO . $info['id'], $appId, '', self::TTL);

        foreach ($info[self::INTERNAL]['localizations'] as $localization) {
            $cache->save(self::CACHE_INFO_LOCALIZATION . $localization['id'], $info['id'], '', self::TTL);
        }
    }
}
