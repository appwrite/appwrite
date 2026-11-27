<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google;

use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Platform\Scope\HTTP;

abstract class Base extends Action
{
    use HTTP;

    protected const string PREFIX = '/v1/mock/distribution/google';
    protected const string SEEDS = self::PREFIX . '/seeds';
    protected const string APPLICATIONS = self::PREFIX . '/androidpublisher/v3/applications';
    protected const string RESUMABLE = self::PREFIX . '/resumable/upload/androidpublisher/v3/applications';
    protected const string SCOPE = 'https://www.googleapis.com/auth/androidpublisher';
    protected const string AUDIENCE = 'https://oauth2.googleapis.com/token';
    protected const string GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    protected const array TRACKS = ['production', 'beta', 'alpha', 'internal'];
    protected const array CONTENT = ['tracks', 'bundles', 'listings'];
    protected const array IMAGE_LIMITS = [
        'phoneScreenshots' => 8,
        'sevenInchScreenshots' => 8,
        'tenInchScreenshots' => 8,
        'tvScreenshots' => 8,
        'wearScreenshots' => 8,
        'icon' => 1,
        'featureGraphic' => 1,
        'tvBanner' => 1,
    ];
    protected const int IMAGE_SIZE = 15728640;
    protected const array LISTING_FIELDS = ['title', 'shortDescription', 'fullDescription', 'video'];
    protected const int TTL = 86400;
    protected const int TOKEN_LIFETIME = 3600;

    protected const string RESOURCE_ACCOUNT = 'account';
    protected const string RESOURCE_TOKEN = 'token';
    protected const string RESOURCE_APPLICATION = 'application';
    protected const string RESOURCE_EDIT = 'edit';
    protected const string RESOURCE_CURRENT = 'current';
    protected const string RESOURCE_SESSION = 'session';
    protected const string RESOURCE_PART = 'part';
    protected const string RESOURCE_BUNDLE = 'bundle';
    protected const string RESOURCE_REVIEW = 'review';
    protected const string RESOURCE_UPLOADS = 'uploads';

    protected const string LOCK_TARGET = 'mock';
    protected const int LOCK_TTL = 10;
    protected const float LOCK_WAIT = 10.0;

    protected const string STATUS_DRAFT = 'draft';
    protected const string STATUS_IN_PROGRESS = 'inProgress';
    protected const string STATUS_HALTED = 'halted';
    protected const string STATUS_COMPLETED = 'completed';
    protected const array STATUSES = [self::STATUS_DRAFT, self::STATUS_IN_PROGRESS, self::STATUS_HALTED, self::STATUS_COMPLETED];

    protected const string STATE_UNSPECIFIED = 'RELEASE_LIFECYCLE_STATE_UNSPECIFIED';
    protected const string STATE_DRAFT = 'RELEASE_LIFECYCLE_STATE_DRAFT';
    protected const string STATE_NOT_SENT_FOR_REVIEW = 'RELEASE_LIFECYCLE_STATE_NOT_SENT_FOR_REVIEW';
    protected const string STATE_IN_REVIEW = 'RELEASE_LIFECYCLE_STATE_IN_REVIEW';
    protected const string STATE_APPROVED_NOT_PUBLISHED = 'RELEASE_LIFECYCLE_STATE_APPROVED_NOT_PUBLISHED';
    protected const string STATE_NOT_APPROVED = 'RELEASE_LIFECYCLE_STATE_NOT_APPROVED';
    protected const string STATE_PUBLISHED = 'RELEASE_LIFECYCLE_STATE_PUBLISHED';
    protected const array STATES = [
        self::STATE_UNSPECIFIED,
        self::STATE_DRAFT,
        self::STATE_NOT_SENT_FOR_REVIEW,
        self::STATE_IN_REVIEW,
        self::STATE_APPROVED_NOT_PUBLISHED,
        self::STATE_NOT_APPROVED,
        self::STATE_PUBLISHED,
    ];

    protected const string DRAFT_APP = 'Only releases with status draft may be created on draft app.';

    protected function key(string $resource, string $id): string
    {
        return 'mock:distribution:google:' . $resource . ':' . $id;
    }

    protected function current(string $packageName, string $email): string
    {
        return $this->key(self::RESOURCE_CURRENT, $packageName . ':' . $email);
    }

    protected function authorize(Request $request, Response $response, Cache $cache): ?string
    {
        $header = $request->getHeaderLine('authorization');

        if (!\str_starts_with($header, 'Bearer ')) {
            $this->unauthenticated($response);
            return null;
        }

        $email = $cache->load($this->key(self::RESOURCE_TOKEN, \substr($header, \strlen('Bearer '))), self::TOKEN_LIFETIME);

        if (!\is_string($email) || $email === '') {
            $this->unauthenticated($response);
            return null;
        }

        return $email;
    }

    protected function origin(Request $request): string
    {
        $port = $request->getPort();
        $origin = $request->getProtocol() . '://' . $request->getHostname();

        return $port === '' ? $origin : $origin . ':' . $port;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function application(Response $response, Cache $cache, string $packageName, string $email): ?array
    {
        $application = $cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL);

        if (!\is_array($application)) {
            $this->notFound($response, 'applicationNotFound', 'Package not found: ' . $packageName . '.');
            return null;
        }

        if (!\in_array($email, $application['emails'] ?? [], true)) {
            $this->forbidden($response);
            return null;
        }

        return $application;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function edit(Request $request, Response $response, Cache $cache, string $packageName, string $editId): ?array
    {
        $email = $this->authorize($request, $response, $cache);

        if ($email === null || $this->application($response, $cache, $packageName, $email) === null) {
            return null;
        }

        $edit = $this->open($cache, $packageName, $email, $editId);

        if ($edit === null) {
            $this->notFound($response, 'editNotFound', 'This Edit has been deleted.');
            return null;
        }

        return $edit;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function open(Cache $cache, string $packageName, string $email, string $editId): ?array
    {
        $edit = $cache->load($this->key(self::RESOURCE_EDIT, $editId), self::TTL);

        if (
            !\is_array($edit)
            || ($edit['packageName'] ?? '') !== $packageName
            || ($edit['email'] ?? '') !== $email
            || (int) ($edit['expiryTimeSeconds'] ?? 0) < \time()
            || $cache->load($this->current($packageName, $email), self::TTL) !== $editId
        ) {
            return null;
        }

        return $edit;
    }

    /**
     * @param array<string, mixed> $edit
     */
    protected function saveEdit(Cache $cache, array $edit): void
    {
        $cache->save($this->key(self::RESOURCE_EDIT, (string) $edit['id']), $edit, '', self::TTL);
    }

    protected function locked(Lock $lock, string $packageName, \Closure $callback): mixed
    {
        return $lock->withKey(
            'lock:' . $this->key(self::RESOURCE_APPLICATION, $packageName),
            $callback,
            target: self::LOCK_TARGET,
            ttl: self::LOCK_TTL,
            waitTimeout: self::LOCK_WAIT,
        );
    }

    protected function count(Lock $lock, Cache $cache, string $packageName, string $counter, int $amount): void
    {
        $this->locked($lock, $packageName, function () use ($cache, $packageName, $counter, $amount): void {
            $key = $this->key(self::RESOURCE_UPLOADS, $packageName);
            $uploads = $cache->load($key, self::TTL);
            $uploads = \is_array($uploads) ? $uploads : [];
            $uploads[$counter] = (int) ($uploads[$counter] ?? 0) + $amount;

            $cache->save($key, $uploads, '', self::TTL);
        });
    }

    /**
     * @return array{inReview: bool, notSentForReview: bool, draft: bool, states: array<string, string>, releases: array<string, list<array<string, mixed>>>}
     */
    protected function review(Cache $cache, string $packageName): array
    {
        $review = $cache->load($this->key(self::RESOURCE_REVIEW, $packageName), self::TTL);
        $review = \is_array($review) ? $review : [];

        return [
            'inReview' => (bool) ($review['inReview'] ?? false),
            'notSentForReview' => (bool) ($review['notSentForReview'] ?? false),
            'draft' => (bool) ($review['draft'] ?? false),
            'states' => \is_array($review['states'] ?? null) ? $review['states'] : [],
            'releases' => \is_array($review['releases'] ?? null) ? $review['releases'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $review
     */
    protected function saveReview(Cache $cache, string $packageName, array $review): void
    {
        $cache->save($this->key(self::RESOURCE_REVIEW, $packageName), $review, '', self::TTL);
    }

    /**
     * @param array<mixed> $tracks
     */
    protected function locate(array $tracks, string $name): ?int
    {
        foreach ($tracks as $index => $track) {
            if (\is_array($track) && ($track['track'] ?? null) === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $tracks
     * @return list<string>
     */
    protected function versionCodes(array $tracks): array
    {
        $codes = [];

        foreach ($tracks as $track) {
            foreach (\is_array($track) ? ($track['releases'] ?? []) : [] as $release) {
                foreach (\is_array($release) ? ($release['versionCodes'] ?? []) : [] as $code) {
                    $codes[] = (string) $code;
                }
            }
        }

        return \array_values(\array_unique($codes));
    }

    /**
     * @param array<mixed> $bundles
     * @return list<string>
     */
    protected function bundleCodes(array $bundles): array
    {
        return \array_values(\array_map(
            fn (mixed $bundle): string => (string) (\is_array($bundle) ? ($bundle['versionCode'] ?? '') : ''),
            $bundles,
        ));
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{id: string, expiryTimeSeconds: string}
     */
    protected function appEdit(array $edit): array
    {
        return [
            'id' => (string) $edit['id'],
            'expiryTimeSeconds' => (string) $edit['expiryTimeSeconds'],
        ];
    }

    /**
     * @param array<string, mixed> $listing
     * @return array<string, string>
     */
    protected function listing(string $language, array $listing): array
    {
        $shape = ['language' => $language];

        foreach (self::LISTING_FIELDS as $field) {
            if (\is_string($listing[$field] ?? null) && $listing[$field] !== '') {
                $shape[$field] = $listing[$field];
            }
        }

        return $shape;
    }

    protected function grantError(Response $response, string $description): void
    {
        $response
            ->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)
            ->json([
                'error' => 'invalid_grant',
                'error_description' => $description,
            ]);
    }

    protected function unauthenticated(Response $response): void
    {
        $this->error(
            $response,
            Response::STATUS_CODE_UNAUTHORIZED,
            'UNAUTHENTICATED',
            'authError',
            'Request had invalid authentication credentials. Expected OAuth 2 access token, login cookie or other valid authentication credential.',
        );
    }

    protected function forbidden(Response $response): void
    {
        $this->error($response, Response::STATUS_CODE_FORBIDDEN, 'PERMISSION_DENIED', 'forbidden', 'The caller does not have permission');
    }

    protected function notFound(Response $response, string $reason, string $message): void
    {
        $this->error($response, Response::STATUS_CODE_NOT_FOUND, 'NOT_FOUND', $reason, $message);
    }

    protected function badRequest(Response $response, string $message): void
    {
        $this->error($response, Response::STATUS_CODE_BAD_REQUEST, 'INVALID_ARGUMENT', 'badRequest', $message);
    }

    protected function versionConflict(Response $response): void
    {
        $this->error($response, Response::STATUS_CODE_FORBIDDEN, 'PERMISSION_DENIED', 'apkUpgradeVersionConflict', 'APK specifies a version code that has already been used.');
    }

    protected function failedPrecondition(Response $response, string $editId): void
    {
        $response
            ->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)
            ->json([
                'error' => [
                    'code' => Response::STATUS_CODE_BAD_REQUEST,
                    'message' => 'You already have changes in review. Cancel this review or wait for it to complete before you try again. See https://developers.google.com/android-publisher/api-ref/rest/v3/applications.tracks.releases',
                    'status' => 'FAILED_PRECONDITION',
                    'details' => [
                        [
                            '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                            'reason' => 'CHANGES_ALREADY_IN_REVIEW',
                            'domain' => 'googleapis.com',
                            'metadata' => [
                                'editId' => $editId,
                                'method' => 'edits.commit',
                                'service' => 'androidpublisher.googleapis.com',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    private function error(Response $response, int $code, string $status, string $reason, string $message): void
    {
        $response
            ->setStatusCode($code)
            ->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'status' => $status,
                    'errors' => [
                        [
                            'reason' => $reason,
                            'domain' => 'global',
                            'message' => $message,
                        ],
                    ],
                ],
            ]);
    }
}
