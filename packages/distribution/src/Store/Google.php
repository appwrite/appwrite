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
use Utopia\Distribution\Store;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Google extends Store
{
    public const string NAME = 'google';

    public const string ENDPOINT = 'https://androidpublisher.googleapis.com';

    public const string TOKEN = 'https://oauth2.googleapis.com/token';

    public const string SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public const string GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    public const int TOKEN_LIFETIME = 3600;

    public const int TOKEN_MARGIN = 300;

    private const string APPLICATIONS = '/androidpublisher/v3/applications';

    private const string INVALID_GRANT = 'invalid_grant';

    private const string SIGNATURE = 'Signature';

    private const string ACCOUNT_NOT_FOUND = 'account not found';

    private const string FAILED_PRECONDITION = 'FAILED_PRECONDITION';

    private const string CHANGES_ALREADY_IN_REVIEW = 'CHANGES_ALREADY_IN_REVIEW';

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
        $edit = $this->insert($identifier);
        $this->discard($identifier, $edit);

        return new App($identifier, $identifier);
    }

    #[\Override]
    public function latestVersionCode(string $identifier): int
    {
        $edit = $this->insert($identifier);

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

    protected function now(): int
    {
        return time();
    }

    private function insert(string $identifier): string
    {
        $body = $this->call($this->factory->json(Method::POST, $this->edits($identifier), new \stdClass()));

        $id = $body['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            throw new Unavailable('Google Play returned an edit without an id');
        }

        return $id;
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

    private function edits(string $identifier, ?string $edit = null): string
    {
        if ($identifier === '') {
            throw new Invalid('Google Play package name must not be empty');
        }

        $path = $this->endpoint . self::APPLICATIONS . '/' . rawurlencode($identifier) . '/edits';

        return $edit === null ? $path : $path . '/' . rawurlencode($edit);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function call(RequestInterface $request): array
    {
        $response = $this->send($this->authorize($request));

        if ($response->getStatusCode() === 401) {
            $this->accessToken = null;
            $response = $this->send($this->authorize($request));
        }

        $contents = (string) $response->getBody();
        $body = $this->decode($contents);

        if (!$this->successful($response)) {
            throw $this->failure($response->getStatusCode(), $body, $contents);
        }

        return $body;
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
            $status === 404 => new NotFound($message, $issues, body: $contents),
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
}
