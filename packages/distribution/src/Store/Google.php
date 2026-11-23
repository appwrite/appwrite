<?php

declare(strict_types=1);

namespace Utopia\Distribution\Store;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
use Utopia\Distribution\State;
use Utopia\Distribution\Store;
use Utopia\Distribution\Upload;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;
use Utopia\Storage\Device;

class Google extends Store
{
    public const string NAME = 'google';

    public const string ENDPOINT = 'https://androidpublisher.googleapis.com';

    public const string TOKEN = 'https://oauth2.googleapis.com/token';

    public const string SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public const string GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    public const int TOKEN_LIFETIME = 3600;

    public const int TOKEN_MARGIN = 300;

    public const string UPLOAD_SESSION_ENDED = 'uploadSessionEnded';

    public const int CHUNK = 8388608;

    public const int NOTES_LIMIT = 500;

    public const int IMAGE_LIMIT = 15728640;

    private const string APPLICATIONS = '/androidpublisher/v3/applications';

    private const string INVALID_GRANT = 'invalid_grant';

    private const string SIGNATURE = 'Signature';

    private const string ACCOUNT_NOT_FOUND = 'account not found';

    private const string FAILED_PRECONDITION = 'FAILED_PRECONDITION';

    private const string CHANGES_ALREADY_IN_REVIEW = 'CHANGES_ALREADY_IN_REVIEW';

    private const string RESUMABLE = '/resumable/upload';

    private const string UPLOAD_TYPE = 'resumable';

    private const string UPLOAD_CONTENT_TYPE = 'X-Upload-Content-Type';

    private const string UPLOAD_CONTENT_LENGTH = 'X-Upload-Content-Length';

    private const string CONTENT_RANGE = 'Content-Range';

    private const string RANGE = 'Range';

    private const string RANGE_UNIT = 'bytes=';

    private const int INCOMPLETE = 308;

    private const array SESSION_ENDED = [404, 410];

    private const array EDIT_INVALID = ['editNotFound', 'editExpired', 'editAlreadyCommitted'];

    private const string QA = 'qa';

    private const string IN_REVIEW_BEHAVIOR = 'ERROR_IF_IN_REVIEW';

    private const string NOT_SENT_FOR_REVIEW = 'Please set the query parameter changesNotSentForReview to true';

    private const string DRAFT_ONLY = 'Only releases with status draft may be created on draft app';

    private const string RELEASE_DRAFT = 'draft';

    private const string RELEASE_IN_PROGRESS = 'inProgress';

    private const string RELEASE_HALTED = 'halted';

    private const string RELEASE_COMPLETED = 'completed';

    private const string LIFECYCLE_UNSPECIFIED = 'RELEASE_LIFECYCLE_STATE_UNSPECIFIED';

    private const string LIFECYCLE_DRAFT = 'RELEASE_LIFECYCLE_STATE_DRAFT';

    private const string LIFECYCLE_NOT_SENT_FOR_REVIEW = 'RELEASE_LIFECYCLE_STATE_NOT_SENT_FOR_REVIEW';

    private const string LIFECYCLE_IN_REVIEW = 'RELEASE_LIFECYCLE_STATE_IN_REVIEW';

    private const string LIFECYCLE_APPROVED_NOT_PUBLISHED = 'RELEASE_LIFECYCLE_STATE_APPROVED_NOT_PUBLISHED';

    private const string LIFECYCLE_NOT_APPROVED = 'RELEASE_LIFECYCLE_STATE_NOT_APPROVED';

    private const string LIFECYCLE_PUBLISHED = 'RELEASE_LIFECYCLE_STATE_PUBLISHED';

    private const string LIFECYCLE_NOT_FOUND = 'NOT_FOUND';

    private const string DRAFT_APP = 'DRAFT_APP';

    private const string UPLOAD = '/upload';

    private const string MEDIA = 'media';

    private const string IMAGE = 'image/*';

    private const array LISTING_FIELDS = [
        Listing::FIELD_NAME => 'title',
        Listing::FIELD_SHORT_DESCRIPTION => 'shortDescription',
        Listing::FIELD_DESCRIPTION => 'fullDescription',
    ];

    private readonly Factory $factory;

    private ?string $accessToken = null;

    private int $expiresAt = 0;

    public function __construct(
        private readonly Credential\Google $credential,
        ClientInterface $client,
        string $endpoint = self::ENDPOINT,
        private readonly string $token = self::TOKEN,
    ) {
        parent::__construct($client, $endpoint);

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
        $this->accessToken();

        return new Account($this->credential->email);
    }

    #[\Override]
    public function app(string $identifier): App
    {
        $edit = $this->insert($identifier)['id'];
        $this->discard($identifier, $edit);

        return new App($identifier, $identifier);
    }

    #[\Override]
    public function latestVersionCode(string $identifier): int
    {
        $edit = $this->insert($identifier)['id'];

        try {
            $body = $this->call($this->factory->createRequest(Method::GET, $this->edits($identifier, $edit) . '/tracks'));
        } catch (\Throwable $throwable) {
            $this->discard($identifier, $edit);

            throw $throwable;
        }

        $this->delete($identifier, $edit);

        $latest = 0;
        foreach ($this->list($body, 'tracks') as $track) {
            foreach ($this->list($track, 'releases') as $release) {
                foreach ($this->list($release, 'versionCodes') as $versionCode) {
                    if (\is_int($versionCode) || (\is_string($versionCode) && ctype_digit($versionCode))) {
                        $latest = max($latest, (int) $versionCode);
                    }
                }
            }
        }

        return $latest;
    }

    /**
     * @param array<string, mixed>|null $checkpoint
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function upload(Upload $upload, ?array $checkpoint, ?Progress $progress = null): array
    {
        if ($upload->platform !== Upload::PLATFORM_ANDROID) {
            throw new Invalid("Google Play does not accept {$upload->platform} uploads");
        }

        $session = $this->restore($upload, $checkpoint);
        $response = $session === null ? null : $this->probe($upload, $session['session']);

        if ($session === null || ($response instanceof ResponseInterface && \in_array($response->getStatusCode(), self::SESSION_ENDED, true))) {
            $session = $this->open($upload);
            $response = null;
            $progress?->save([...$session, 'offset' => 0]);
        }

        $bundle = $this->transfer($upload, $session, $response, $progress);

        unset($session['session']);

        return [...$session, ...$bundle];
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function processed(array $external): array
    {
        return $external;
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function release(Release $release, array $external): array
    {
        $packageName = $this->field($external, 'packageName');
        $editId = $this->field($external, 'editId');
        $versionCode = $this->code($external);
        $notes = $this->notes($release);

        $track = $this->track($packageName, $editId, $release->track);
        $entry = ['versionCodes' => [$versionCode]];

        $releases = $release->fraction === 1.0
            ? [[...$entry, 'status' => self::RELEASE_COMPLETED, ...$notes]]
            : [
                ...array_values(array_filter($this->releases($packageName, $editId, $track), $this->completed(...))),
                [...$entry, 'status' => self::RELEASE_IN_PROGRESS, 'userFraction' => $release->fraction, ...$notes],
            ];

        $draft = false;
        try {
            $this->update($packageName, $editId, $track, $releases);
        } catch (Invalid $invalid) {
            if (!$this->mentions($invalid, self::DRAFT_ONLY)) {
                throw $invalid;
            }

            $this->update($packageName, $editId, $track, [[...$entry, 'status' => self::RELEASE_DRAFT, ...$notes]]);
            $draft = true;
        }

        $unsent = $this->commit($packageName, $editId);

        $external = [...$external, 'track' => $track, 'committed' => true];
        unset($external['changesNotSentForReview'], $external['draft']);

        if ($unsent) {
            $external['changesNotSentForReview'] = true;
        }

        if ($draft) {
            $external['draft'] = true;
        }

        return $external;
    }

    /**
     * @param array<string, mixed> $external
     */
    #[\Override]
    public function state(array $external, string $track): State
    {
        $packageName = $this->field($external, 'packageName');
        $versionCode = $this->code($external);
        $track = $this->alias($external, $track);

        $lifecycle = $this->lifecycle($packageName, $track, $versionCode);

        return match ($lifecycle) {
            self::LIFECYCLE_DRAFT => new State(
                State::STATUS_PENDING,
                $lifecycle,
                issues: ($external['draft'] ?? false) === true ? [new Issue(self::DRAFT_APP, 'The app is a draft; publish it from Play Console', manual: true)] : [],
            ),
            self::LIFECYCLE_NOT_SENT_FOR_REVIEW => new State(
                State::STATUS_PENDING,
                $lifecycle,
                issues: [new Issue($lifecycle, 'The release must be sent for review from Play Console', manual: true)],
            ),
            self::LIFECYCLE_IN_REVIEW => new State(State::STATUS_REVIEWING, $lifecycle),
            self::LIFECYCLE_APPROVED_NOT_PUBLISHED => new State(State::STATUS_APPROVED, $lifecycle),
            self::LIFECYCLE_NOT_APPROVED => new State(State::STATUS_REJECTED, $lifecycle),
            self::LIFECYCLE_PUBLISHED => $this->published($packageName, $track, $versionCode),
            default => new State(State::STATUS_PENDING, $lifecycle),
        };
    }

    /**
     * @param array<string, mixed> $external
     */
    #[\Override]
    public function rollout(array $external, string $track, float $fraction): void
    {
        if (is_nan($fraction) || $fraction <= 0.0 || $fraction > 1.0) {
            throw new Invalid('Rollout fraction must be greater than 0 and at most 1');
        }

        $this->change(
            $external,
            $track,
            fn (array $release): array => $fraction === 1.0
                ? $this->complete($release)
                : [...$release, 'status' => self::RELEASE_IN_PROGRESS, 'userFraction' => $fraction],
        );
    }

    /**
     * @param array<string, mixed> $external
     */
    #[\Override]
    public function halt(array $external, string $track): void
    {
        $this->change($external, $track, static fn (array $release): array => [...$release, 'status' => self::RELEASE_HALTED]);
    }

    /**
     * @param array<string, mixed> $external
     */
    #[\Override]
    public function resume(array $external, string $track): void
    {
        $this->change(
            $external,
            $track,
            fn (array $release): array => $this->fraction($release) < 1.0
                ? [...$release, 'status' => self::RELEASE_IN_PROGRESS]
                : $this->complete($release),
        );
    }

    /**
     * @param array<string, mixed> $external
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function listing(Listing $listing, array $external): array
    {
        $packageName = $listing->identifier;
        $sets = $this->sets($listing);

        $editId = $external['editId'] ?? null;

        if (
            ($external['packageName'] ?? null) === $packageName
            && \is_string($editId) && $editId !== ''
            && ($external['committed'] ?? false) !== true
        ) {
            $this->write($packageName, $editId, $listing, $sets);

            return [...$external, 'committed' => false];
        }

        $editId = $this->insert($packageName)['id'];

        try {
            $this->write($packageName, $editId, $listing, $sets);

            $unsent = $this->commit($packageName, $editId);
        } catch (\Throwable $throwable) {
            $this->discard($packageName, $editId);

            throw $throwable;
        }

        $result = ['packageName' => $packageName, 'committed' => true];

        if ($unsent) {
            $result['changesNotSentForReview'] = true;
        }

        return $result;
    }

    /**
     * @return int<1, max>
     */
    protected function chunk(): int
    {
        return self::CHUNK;
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * @return array{id: string, expiryTimeSeconds?: int}
     */
    private function insert(string $identifier): array
    {
        $body = $this->call($this->factory->json(Method::POST, $this->edits($identifier), new \stdClass()));

        $id = $body['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            throw new Unavailable('Google Play returned an edit without an id');
        }

        $expiry = $this->expiry($body);

        return $expiry === null ? ['id' => $id] : ['id' => $id, 'expiryTimeSeconds' => $expiry];
    }

    /**
     * @param array<array-key, mixed> $edit
     */
    private function expiry(array $edit): ?int
    {
        $expiry = $edit['expiryTimeSeconds'] ?? null;

        return match (true) {
            \is_int($expiry) => $expiry,
            \is_string($expiry) && ctype_digit($expiry) => (int) $expiry,
            default => null,
        };
    }

    private function delete(string $identifier, string $edit): void
    {
        $this->call($this->factory->createRequest(Method::DELETE, $this->edits($identifier, $edit)));
    }

    private function discard(string $identifier, string $edit): void
    {
        try {
            $this->delete($identifier, $edit);
        } catch (Exception) {
            // Best effort: the edit expires on its own, and the caller's own result or failure is the one to report.
        }
    }

    private function edits(string $identifier, ?string $edit = null, string $prefix = ''): string
    {
        $path = $this->application($identifier, $prefix) . '/edits';

        return $edit === null ? $path : $path . '/' . rawurlencode($edit);
    }

    private function application(string $identifier, string $prefix = ''): string
    {
        if ($identifier === '') {
            throw new Invalid('Google Play package name must not be empty');
        }

        return $this->endpoint . $prefix . self::APPLICATIONS . '/' . rawurlencode($identifier);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function call(RequestInterface $request): array
    {
        return $this->decode((string) $this->respond($request)->getBody());
    }

    private function respond(RequestInterface $request, int ...$expected): ResponseInterface
    {
        $response = $this->send($this->authorize($request));

        if ($response->getStatusCode() === 401) {
            $this->accessToken = null;

            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $response = $this->send($this->authorize($request));
        }

        $status = $response->getStatusCode();

        if (!$this->successful($response) && !\in_array($status, $expected, true)) {
            $contents = (string) $response->getBody();

            throw $this->failure($status, $this->decode($contents), $contents);
        }

        return $response;
    }

    private function authorize(RequestInterface $request): RequestInterface
    {
        return $request->withHeader(Header::AUTHORIZATION, 'Bearer ' . $this->accessToken());
    }

    private function accessToken(): string
    {
        $now = $this->now();

        if ($this->accessToken !== null && $now < $this->expiresAt) {
            return $this->accessToken;
        }

        $assertion = new JWT($this->credential->privateKey, JWT::RS256)->encode([
            'iss' => $this->credential->email,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN,
            'iat' => $now,
            'exp' => $now + self::TOKEN_LIFETIME,
        ]);

        $response = $this->send($this->factory->form(Method::POST, $this->token, [
            'grant_type' => self::GRANT,
            'assertion' => $assertion,
        ]));

        $contents = (string) $response->getBody();
        $body = $this->decode($contents);

        if (!$this->successful($response)) {
            throw $this->grantFailure($response->getStatusCode(), $body, $contents);
        }

        $accessToken = $body['access_token'] ?? null;
        if (!\is_string($accessToken) || $accessToken === '') {
            throw new Unavailable('Google returned a token response without an access token');
        }

        $expiresIn = $body['expires_in'] ?? null;

        $this->accessToken = $accessToken;
        $this->expiresAt = $now + (\is_int($expiresIn) ? $expiresIn : self::TOKEN_LIFETIME) - self::TOKEN_MARGIN;

        return $accessToken;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function grantFailure(int $status, array $body, string $contents): Exception
    {
        $error = $body['error'] ?? null;

        if ($status !== 400 || $error !== self::INVALID_GRANT) {
            return $this->failure($status, $body, $contents);
        }

        $description = $body['error_description'] ?? null;
        $description = \is_string($description) ? $description : self::INVALID_GRANT;

        return new Unauthorized(
            "Google rejected the service account assertion: {$description}",
            [new Issue(self::INVALID_GRANT, $description)],
            credential: str_contains($description, self::SIGNATURE) || stripos($description, self::ACCOUNT_NOT_FOUND) !== false,
            body: $contents,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function failure(int $status, array $body, string $contents): Exception
    {
        $error = $body['error'] ?? null;
        $error = \is_array($error) ? $error : [];

        $message = $error['message'] ?? null;
        $message = "Google Play returned {$status}" . (\is_string($message) ? ": {$message}" : '');

        $issues = $this->issues($body, $message);
        $reasons = array_map(static fn (Issue $issue): string => $issue->code, $issues);

        return match (true) {
            $status === 401, $status === 403 => new Unauthorized($message, $issues, body: $contents),
            $status === 404,
            $status === 400 && array_intersect(self::EDIT_INVALID, $reasons) !== [] => new NotFound($message, $issues, body: $contents),
            $status === 409,
            $status === 400 && (($error['status'] ?? null) === self::FAILED_PRECONDITION || \in_array(self::CHANGES_ALREADY_IN_REVIEW, $reasons, true)) => new Conflict($message, $issues, body: $contents),
            $status === 429 => new RateLimited($message, $issues, body: $contents),
            $status >= 400 && $status < 500 => new Invalid($message, $issues, body: $contents),
            default => new Unavailable($message, $issues, body: $contents),
        };
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<Issue>
     */
    private function issues(array $body, string $fallback): array
    {
        $error = $body['error'] ?? null;

        if (\is_string($error)) {
            $description = $body['error_description'] ?? null;

            return [new Issue($error, \is_string($description) ? $description : $fallback)];
        }

        if (!\is_array($error)) {
            return [];
        }

        $message = $error['message'] ?? null;
        $message = \is_string($message) ? $message : $fallback;

        $issues = [];
        foreach ([...$this->list($error, 'errors'), ...$this->list($error, 'details')] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $reason = $entry['reason'] ?? null;
            if (!\is_string($reason)) {
                continue;
            }

            $detail = $entry['message'] ?? null;
            $issues[] = new Issue($reason, \is_string($detail) ? $detail : $message);
        }

        $status = $error['status'] ?? null;
        if ($issues === [] && \is_string($status)) {
            $issues[] = new Issue($status, $message);
        }

        return $issues;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $container, string $key): array
    {
        $value = \is_array($container) ? ($container[$key] ?? null) : null;

        return \is_array($value) ? array_values($value) : [];
    }

    private function successful(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $checkpoint
     *
     * @return array{packageName: string, editId: string, expiryTimeSeconds?: int, session: string}|null
     */
    private function restore(Upload $upload, ?array $checkpoint): ?array
    {
        $editId = $checkpoint['editId'] ?? null;
        $session = $checkpoint['session'] ?? null;

        if (
            ($checkpoint['packageName'] ?? null) !== $upload->identifier
            || !\is_string($editId) || $editId === ''
            || !\is_string($session) || $session === ''
        ) {
            return null;
        }

        try {
            $edit = $this->call($this->factory->createRequest(Method::GET, $this->edits($upload->identifier, $editId)));
        } catch (NotFound) {
            return null;
        }

        return $this->session($upload, ['id' => $editId, 'expiryTimeSeconds' => $this->expiry($edit) ?? $this->expiry($checkpoint)], $session);
    }

    /**
     * @return array{packageName: string, editId: string, expiryTimeSeconds?: int, session: string}
     */
    private function open(Upload $upload): array
    {
        $edit = $this->insert($upload->identifier);
        $editId = $edit['id'];

        $response = $this->respond($this->factory->query(
            Method::POST,
            $this->edits($upload->identifier, $editId, self::RESUMABLE) . '/bundles',
            ['uploadType' => self::UPLOAD_TYPE],
            [
                self::UPLOAD_CONTENT_TYPE => ContentType::OCTET_STREAM,
                self::UPLOAD_CONTENT_LENGTH => (string) $upload->size,
                Header::CONTENT_LENGTH => '0',
            ],
        ));

        $session = $response->getHeaderLine(Header::LOCATION);
        if ($session === '') {
            throw new Unavailable('Google Play did not return an upload session');
        }

        return $this->session($upload, $edit, $session);
    }

    /**
     * @param array{id: string, expiryTimeSeconds?: int|null} $edit
     *
     * @return array{packageName: string, editId: string, expiryTimeSeconds?: int, session: string}
     */
    private function session(Upload $upload, array $edit, #[\SensitiveParameter] string $session): array
    {
        $expiry = $edit['expiryTimeSeconds'] ?? null;

        return $expiry === null
            ? ['packageName' => $upload->identifier, 'editId' => $edit['id'], 'session' => $session]
            : ['packageName' => $upload->identifier, 'editId' => $edit['id'], 'expiryTimeSeconds' => $expiry, 'session' => $session];
    }

    private function probe(Upload $upload, #[\SensitiveParameter] string $session): ResponseInterface
    {
        return $this->respond(
            $this->factory->createRequest(Method::PUT, $session)
                ->withHeader(Header::CONTENT_LENGTH, '0')
                ->withHeader(self::CONTENT_RANGE, "bytes */{$upload->size}"),
            self::INCOMPLETE,
            ...self::SESSION_ENDED,
        );
    }

    /**
     * @param array{packageName: string, editId: string, expiryTimeSeconds?: int, session: string} $session
     *
     * @return array{versionCode: int, sha256: string}
     */
    private function transfer(Upload $upload, #[\SensitiveParameter] array $session, ?ResponseInterface $response, ?Progress $progress): array
    {
        if ($response instanceof ResponseInterface && $response->getStatusCode() !== self::INCOMPLETE) {
            return $this->bundle($response);
        }

        $offset = $response instanceof ResponseInterface ? $this->received($response) : 0;

        while (true) {
            $remaining = $upload->size - $offset;
            $response = $remaining > 0
                ? $this->part($upload, $session['session'], $offset, min($this->chunk(), $remaining))
                : $this->probe($upload, $session['session']);

            if (\in_array($response->getStatusCode(), self::SESSION_ENDED, true)) {
                $message = 'Google Play ended the upload session before returning the bundle; the next attempt starts a new upload';

                throw new NotFound($message, [new Issue(self::UPLOAD_SESSION_ENDED, $message)]);
            }

            if ($response->getStatusCode() !== self::INCOMPLETE) {
                return $this->bundle($response);
            }

            $received = $this->received($response);
            if ($received <= $offset) {
                throw new Unavailable("Google Play stopped accepting the upload at byte {$offset}");
            }

            $offset = $received;
            $progress?->save([...$session, 'offset' => $offset]);
        }
    }

    /**
     * @param int<0, max> $offset
     * @param int<1, max> $length
     */
    private function part(Upload $upload, #[\SensitiveParameter] string $session, int $offset, int $length): ResponseInterface
    {
        $last = $offset + $length - 1;

        return $this->respond(
            $this->factory->createRequest(Method::PUT, $session)
                ->withHeader(Header::CONTENT_LENGTH, (string) $length)
                ->withHeader(self::CONTENT_RANGE, "bytes {$offset}-{$last}/{$upload->size}")
                ->withBody($upload->device->read($upload->path, $offset, $length)),
            self::INCOMPLETE,
        );
    }

    /**
     * @return int<0, max>
     */
    private function received(ResponseInterface $response): int
    {
        $range = $response->getHeaderLine(self::RANGE);
        if ($range === '') {
            return 0;
        }

        $bounds = str_starts_with($range, self::RANGE_UNIT) ? explode('-', substr($range, \strlen(self::RANGE_UNIT)), 2) : [];
        $last = $bounds[1] ?? '';
        $received = (int) $last + 1;

        if (($bounds[0] ?? null) !== '0' || !ctype_digit($last) || $received < 1) {
            throw new Unavailable("Google Play returned an unreadable upload range \"{$range}\"");
        }

        return $received;
    }

    /**
     * @return array{versionCode: int, sha256: string}
     */
    private function bundle(ResponseInterface $response): array
    {
        $body = $this->decode((string) $response->getBody());

        $versionCode = $body['versionCode'] ?? null;
        $versionCode = \is_string($versionCode) && ctype_digit($versionCode) ? (int) $versionCode : $versionCode;

        $sha256 = $body['sha256'] ?? null;

        if (!\is_int($versionCode) || !\is_string($sha256) || $sha256 === '') {
            throw new Unavailable('Google Play returned a bundle without a version code and sha256');
        }

        return ['versionCode' => $versionCode, 'sha256' => $sha256];
    }

    private function track(string $packageName, string $editId, string $track): string
    {
        $body = $this->call($this->factory->createRequest(Method::GET, $this->edits($packageName, $editId) . '/tracks'));

        $names = [];
        foreach ($this->list($body, 'tracks') as $entry) {
            $name = \is_array($entry) ? ($entry['track'] ?? null) : null;
            if (\is_string($name)) {
                $names[] = $name;
            }
        }

        if ($track === Release::TRACK_INTERNAL && !\in_array($track, $names, true) && \in_array(self::QA, $names, true)) {
            return self::QA;
        }

        return $track;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function releases(string $packageName, string $editId, string $track): array
    {
        $body = $this->call($this->factory->createRequest(Method::GET, $this->tracks($packageName, $editId, $track)));

        return array_values(array_filter($this->list($body, 'releases'), \is_array(...)));
    }

    /**
     * @param list<array<array-key, mixed>> $releases
     */
    private function update(string $packageName, string $editId, string $track, array $releases): void
    {
        $this->call($this->factory->json(Method::PUT, $this->tracks($packageName, $editId, $track), [
            'track' => $track,
            'releases' => $releases,
        ]));
    }

    private function commit(string $packageName, string $editId): bool
    {
        $uri = $this->edits($packageName, $editId) . ':commit';
        $query = ['changesInReviewBehavior' => self::IN_REVIEW_BEHAVIOR];

        try {
            $this->call($this->factory->query(Method::POST, $uri, $query));

            return false;
        } catch (Invalid $invalid) {
            if (!$this->mentions($invalid, self::NOT_SENT_FOR_REVIEW)) {
                throw $invalid;
            }
        }

        $this->call($this->factory->query(Method::POST, $uri, [...$query, 'changesNotSentForReview' => 'true']));

        return true;
    }

    private function tracks(string $packageName, string $editId, string $track): string
    {
        return $this->edits($packageName, $editId) . '/tracks/' . rawurlencode($track);
    }

    private function lifecycle(string $packageName, string $track, string $versionCode): string
    {
        $body = $this->call($this->factory->createRequest(
            Method::GET,
            $this->application($packageName) . '/tracks/' . rawurlencode($track) . '/releases',
        ));

        foreach ($this->list($body, 'releases') as $summary) {
            foreach ($this->list($summary, 'activeArtifacts') as $artifact) {
                $code = \is_array($artifact) ? ($artifact['versionCode'] ?? null) : null;

                if ((\is_int($code) || \is_string($code)) && (string) $code === $versionCode) {
                    $lifecycle = \is_array($summary) ? ($summary['releaseLifecycleState'] ?? null) : null;

                    return \is_string($lifecycle) && $lifecycle !== '' ? $lifecycle : self::LIFECYCLE_UNSPECIFIED;
                }
            }
        }

        return self::LIFECYCLE_NOT_FOUND;
    }

    private function published(string $packageName, string $track, string $versionCode): State
    {
        $editId = $this->insert($packageName)['id'];

        try {
            $releases = $this->releases($packageName, $editId, $track);
        } finally {
            $this->discard($packageName, $editId);
        }

        $index = $this->find($releases, $versionCode);
        $release = $index === null ? [] : $releases[$index];

        return match ($release['status'] ?? null) {
            self::RELEASE_HALTED => new State(State::STATUS_HALTED, self::LIFECYCLE_PUBLISHED, $this->fraction($release)),
            self::RELEASE_IN_PROGRESS => new State(State::STATUS_PUBLISHED, self::LIFECYCLE_PUBLISHED, $this->fraction($release)),
            default => new State(State::STATUS_PUBLISHED, self::LIFECYCLE_PUBLISHED),
        };
    }

    /**
     * @param array<string, mixed> $external
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $transition
     */
    private function change(array $external, string $track, \Closure $transition): void
    {
        $packageName = $this->field($external, 'packageName');
        $versionCode = $this->code($external);
        $track = $this->alias($external, $track);

        $editId = $this->insert($packageName)['id'];

        try {
            $releases = $this->releases($packageName, $editId, $track);

            $index = $this->find($releases, $versionCode);
            if ($index === null) {
                throw new NotFound("Version code {$versionCode} has no release on the {$track} track");
            }

            $changed = $transition($releases[$index]);
            $releases[$index] = $changed;

            if ($this->completed($changed)) {
                $releases = array_filter(
                    $releases,
                    fn (array $release, int $key): bool => $key === $index || !$this->completed($release),
                    ARRAY_FILTER_USE_BOTH,
                );
            }

            $this->update($packageName, $editId, $track, array_values($releases));
            $this->commit($packageName, $editId);
        } catch (\Throwable $throwable) {
            $this->discard($packageName, $editId);

            throw $throwable;
        }
    }

    /**
     * @param list<array<array-key, mixed>> $releases
     */
    private function find(array $releases, string $versionCode): ?int
    {
        foreach ($releases as $index => $release) {
            foreach ($this->list($release, 'versionCodes') as $code) {
                if ((\is_int($code) || \is_string($code)) && (string) $code === $versionCode) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $release
     *
     * @return array<array-key, mixed>
     */
    private function complete(array $release): array
    {
        unset($release['userFraction']);

        return [...$release, 'status' => self::RELEASE_COMPLETED];
    }

    /**
     * @param array<array-key, mixed> $release
     */
    private function completed(array $release): bool
    {
        return ($release['status'] ?? null) === self::RELEASE_COMPLETED;
    }

    /**
     * @param array<array-key, mixed> $release
     */
    private function fraction(array $release): float
    {
        $fraction = $release['userFraction'] ?? null;

        return (\is_float($fraction) || \is_int($fraction)) && $fraction > 0 && $fraction < 1 ? $fraction : 1.0;
    }

    /**
     * @return array{}|array{releaseNotes: list<array{language: string, text: string}>}
     */
    private function notes(Release $release): array
    {
        $notes = [];
        foreach ($release->notes as $language => $text) {
            if ($this->characters($text) > self::NOTES_LIMIT) {
                throw new Invalid("Google Play release notes for {$language} must be at most " . self::NOTES_LIMIT . ' characters');
            }

            $notes[] = ['language' => $language, 'text' => $text];
        }

        return $notes === [] ? [] : ['releaseNotes' => $notes];
    }

    private function characters(string $text): int
    {
        $characters = 0;
        $bytes = \strlen($text);

        for ($index = 0; $index < $bytes; $index++) {
            if ((\ord($text[$index]) & 0xC0) !== 0x80) {
                $characters++;
            }
        }

        return $characters;
    }

    /**
     * @param array<string, mixed> $external
     */
    private function alias(array $external, string $track): string
    {
        return $track === Release::TRACK_INTERNAL && ($external['track'] ?? null) === self::QA ? self::QA : $track;
    }

    /**
     * @param array<string, mixed> $external
     */
    private function field(array $external, string $key): string
    {
        $value = $external[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new Invalid("Google Play external ids are missing {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $external
     */
    private function code(array $external): string
    {
        $versionCode = $external['versionCode'] ?? null;
        $versionCode = \is_int($versionCode) ? (string) $versionCode : $versionCode;

        if (!\is_string($versionCode) || !ctype_digit($versionCode)) {
            throw new Invalid('Google Play external ids are missing versionCode');
        }

        return $versionCode;
    }

    private function mentions(Exception $exception, string $text): bool
    {
        return str_contains($exception->getMessage(), $text)
            || array_any($exception->getIssues(), static fn (Issue $issue): bool => str_contains($issue->message, $text));
    }

    /**
     * @return array<string, non-empty-list<array{set: string, path: string, size: int<1, max>, width: int, height: int, digest: string}>>
     */
    private function sets(Listing $listing): array
    {
        $sets = [];
        foreach ($listing->screenshots as $screenshot) {
            if ($screenshot['size'] < 1 || $screenshot['size'] > self::IMAGE_LIMIT) {
                throw new Invalid("Google Play image {$screenshot['path']} must be between 1 byte and " . self::IMAGE_LIMIT . ' bytes');
            }

            $sets[$screenshot['set']][] = $screenshot;
        }

        return $sets;
    }

    /**
     * @param array<string, non-empty-list<array{set: string, path: string, size: int<1, max>, width: int, height: int, digest: string}>> $sets
     */
    private function write(string $packageName, string $editId, Listing $listing, array $sets): void
    {
        $this->describe($packageName, $editId, $listing);

        foreach ($sets as $type => $screenshots) {
            $this->illustrate($packageName, $editId, $listing, $type, $screenshots);
        }
    }

    private function describe(string $packageName, string $editId, Listing $listing): void
    {
        $fields = [];
        foreach (self::LISTING_FIELDS as $field => $name) {
            if (isset($listing->fields[$field])) {
                $fields[$name] = $listing->fields[$field];
            }
        }

        if ($fields === []) {
            return;
        }

        $uri = $this->listings($packageName, $editId, $listing->locale);

        try {
            $this->call($this->factory->json(Method::PATCH, $uri, $fields));
        } catch (NotFound) {
            $this->call($this->factory->json(Method::PUT, $uri, ['language' => $listing->locale, ...$fields]));
        }
    }

    /**
     * @param non-empty-list<array{set: string, path: string, size: int<1, max>, width: int, height: int, digest: string}> $screenshots
     */
    private function illustrate(string $packageName, string $editId, Listing $listing, string $type, array $screenshots): void
    {
        $device = $listing->device;
        if (!$device instanceof Device) {
            throw new Invalid('Listing screenshots need a device to read them from');
        }

        $uri = $this->listings($packageName, $editId, $listing->locale) . '/' . rawurlencode($type);

        $current = [];
        foreach ($this->list($this->call($this->factory->createRequest(Method::GET, $uri)), 'images') as $image) {
            $sha256 = \is_array($image) ? ($image['sha256'] ?? null) : null;
            $current[] = \is_string($sha256) ? strtolower($sha256) : '';
        }

        $digests = array_map(static fn (array $screenshot): string => strtolower($screenshot['digest']), $screenshots);

        if ($current === $digests) {
            return;
        }

        $this->call($this->factory->createRequest(Method::DELETE, $uri));

        $upload = $this->listings($packageName, $editId, $listing->locale, self::UPLOAD) . '/' . rawurlencode($type);

        foreach ($screenshots as $screenshot) {
            $this->call(
                $this->factory->query(Method::POST, $upload, ['uploadType' => self::MEDIA], [
                    Header::CONTENT_TYPE => self::IMAGE,
                    Header::CONTENT_LENGTH => (string) $screenshot['size'],
                ])->withBody($device->read($screenshot['path'], 0, $screenshot['size'])),
            );
        }
    }

    private function listings(string $packageName, string $editId, string $language, string $prefix = ''): string
    {
        return $this->edits($packageName, $editId, $prefix) . '/listings/' . rawurlencode($language);
    }
}
