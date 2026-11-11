<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\OwnerNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

final class GitHubTest extends Base
{
    /** @var array<string> */
    protected static array $supportedWebhookScopes = [GitHub::WEBHOOK_SCOPE_INSTALLATION, GitHub::WEBHOOK_SCOPE_REPOSITORY];

    protected static string $eventHeader = 'x-github-event';
    protected static string $signatureHeader = 'x-hub-signature-256';

    protected function createAdapter(): GitHub
    {
        return new GitHub(new Cache(new None()));
    }
    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        return (string) json_encode([
            'created' => $created,
            'deleted' => $deleted,
            'ref' => 'refs/heads/' . $branch,
            'before' => 'abc123',
            'after' => self::EVENT_COMMIT_HASH,
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'private' => true,
                'html_url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'owner' => ['name' => self::EVENT_OWNER, 'login' => self::EVENT_OWNER],
            ],
            'installation' => ['id' => 1234],
            'head_commit' => [
                'id' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME . '/commit/' . self::EVENT_COMMIT_HASH,
                'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
            ],
            'commits' => [[
                'id' => self::EVENT_COMMIT_HASH,
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ]],
            'sender' => [
                'html_url' => 'https://github.com/' . self::EVENT_AUTHOR_NAME,
                'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4',
            ],
        ]);
    }

    protected function pullRequestPayload(bool $external = false): string
    {
        $headOwner = $external ? 'someone-else' : self::EVENT_OWNER;

        return (string) json_encode([
            'action' => 'opened',
            'number' => self::EVENT_PULL_REQUEST_NUMBER,
            'pull_request' => [
                'id' => 1303283688,
                'state' => 'open',
                'html_url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME . '/pull/' . self::EVENT_PULL_REQUEST_NUMBER,
                'head' => [
                    'ref' => self::EVENT_HEAD_BRANCH,
                    'sha' => self::EVENT_COMMIT_HASH,
                    'label' => $headOwner . ':' . self::EVENT_HEAD_BRANCH,
                    'user' => ['login' => $headOwner],
                ],
                'base' => [
                    'ref' => self::$defaultBranch,
                    'label' => self::EVENT_OWNER . ':' . self::$defaultBranch,
                    'user' => ['login' => self::EVENT_OWNER],
                ],
                'user' => ['login' => $headOwner, 'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4'],
            ],
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'owner' => ['login' => self::EVENT_OWNER, 'name' => self::EVENT_OWNER],
                'html_url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
            ],
            'installation' => ['id' => 9876],
            'sender' => ['html_url' => 'https://github.com/' . $headOwner],
        ]);
    }

    public function testGetEventInstallation(): void
    {
        $payload = json_encode([
            'action' => 'deleted',
            'installation' => [
                'id' => 1234,
                'account' => ['login' => 'vermakhushboo'],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $events = $this->vcsAdapter->getEvents('installation', $payload);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('deleted', $result['action']);
        $this->assertSame('1234', $result['installationId']);
    }

    public function testGetEventIssueCommentOnPullRequest(): void
    {
        $events = $this->vcsAdapter->getEvents(GitHub::EVENT_ISSUE_COMMENT, $this->commentPayload(GitHub::EVENT_ISSUE_COMMENT));

        $this->assertSame([[
            'repositoryId' => self::EVENT_REPOSITORY_ID,
            'repositoryName' => self::EVENT_REPOSITORY_NAME,
            'owner' => self::EVENT_OWNER,
            'installationId' => '9876',
            'pullRequestNumber' => 42,
            'action' => 'created',
            'commentId' => 1001,
            'commentKind' => 'issue',
            'commentReplyToId' => 0,
            'commentBody' => '@reviewer why?',
            'commentUrl' => 'https://github.com/comment/1001',
            'commentAuthor' => 'octocat',
            'commentAuthorType' => 'User',
            'commentAuthorAssociation' => 'MEMBER',
        ]], $events);
    }

    public function testGetEventIssueCommentOnIssueIsIgnored(): void
    {
        $events = $this->vcsAdapter->getEvents(GitHub::EVENT_ISSUE_COMMENT, $this->commentPayload(GitHub::EVENT_ISSUE_COMMENT, pullRequest: false));

        $this->assertSame([], $events);
    }

    public function testGetEventReviewCommentReply(): void
    {
        $events = $this->vcsAdapter->getEvents(GitHub::EVENT_PULL_REQUEST_REVIEW_COMMENT, $this->commentPayload(GitHub::EVENT_PULL_REQUEST_REVIEW_COMMENT, replyTo: 900));

        $this->assertCount(1, $events);
        $this->assertSame('review', $events[0]['commentKind']);
        $this->assertSame(42, $events[0]['pullRequestNumber']);
        $this->assertSame(900, $events[0]['commentReplyToId']);
    }

    private function commentPayload(string $event, bool $pullRequest = true, ?int $replyTo = null): string
    {
        $comment = [
            'id' => 1001,
            'body' => '@reviewer why?',
            'html_url' => 'https://github.com/comment/1001',
            'author_association' => 'MEMBER',
            'user' => ['login' => 'octocat', 'type' => 'User'],
        ];
        if ($replyTo !== null) {
            $comment['in_reply_to_id'] = $replyTo;
        }

        $payload = [
            'action' => 'created',
            'comment' => $comment,
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'owner' => ['login' => self::EVENT_OWNER],
            ],
            'installation' => ['id' => 9876],
        ];

        if ($event === GitHub::EVENT_ISSUE_COMMENT) {
            $payload['issue'] = ['number' => 42] + ($pullRequest ? ['pull_request' => ['url' => 'https://api.github.com/pulls/42']] : []);
        } else {
            $payload['pull_request'] = ['number' => 42];
        }

        return (string) json_encode($payload);
    }

    public function testUpdateCheckNamesTheStatusAfterItsSuite(): void
    {
        $adapter = new class (new Cache(new None())) extends GitHub {
            /** @var list<array{path: string, params: array<mixed>}> */
            public array $captured = [];

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $this->captured[] = ['path' => $path, 'params' => $params];

                return [
                    'body' => [],
                    'headers' => ['status-code' => 201],
                ];
            }
        };

        $adapter->updateCheck('owner', 'repo', 'abc123', 'queued', 'Billing', 'Starting...', '- Function Billing', 'https://cloud.appwrite.io', 'Atlas Production');
        $adapter->updateCheck('owner', 'repo', 'abc123', 'success', 'Billing', 'Build succeeded.');

        // A commit status has no suite, so the project stays in its context
        $this->assertSame('/repos/owner/repo/statuses/abc123', $adapter->captured[0]['path']);
        $this->assertSame(['state' => 'pending', 'target_url' => 'https://cloud.appwrite.io', 'description' => 'Starting...', 'context' => 'Billing (Atlas Production)'], $adapter->captured[0]['params']);
        $this->assertSame('Billing', $adapter->captured[1]['params']['context']);
    }

    public function testInitializeVariablesSignsJwtWithPemString(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($keyPair);
        openssl_pkey_export($keyPair, $pem);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $adapter = new class (new Cache(new None())) extends GitHub {
            /** @var array<string, mixed> */
            public array $captured = [];

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $this->captured = ['method' => $method, 'path' => $path, 'headers' => $headers];

                return [
                    'body' => ['token' => 'installation-token'],
                    'headers' => ['status-code' => 201],
                ];
            }
        };

        // The key reaches the adapter as a PEM string; it must survive JWT signing.
        $adapter->initializeVariables('1234', $pem, '5678');

        $this->assertSame('/app/installations/1234/access_tokens', $adapter->captured['path']);
        $jwt = substr((string) $adapter->captured['headers']['Authorization'], \strlen('Bearer '));
        [$header, $payload, $signature] = explode('.', $jwt);
        $verified = openssl_verify(
            $header . '.' . $payload,
            base64_decode(strtr($signature, '-_', '+/')),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        $this->assertSame(1, $verified);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(5678, $claims['iss']);
    }

    #[DataProvider('encodedPemProvider')]
    public function testInitializeVariablesSignsJwtWithEncodedPem(callable $encode): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($keyPair);
        openssl_pkey_export($keyPair, $pem);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $adapter = new class (new Cache(new None())) extends GitHub {
            /** @var array<string, mixed> */
            public array $captured = [];

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $this->captured = ['headers' => $headers];

                return [
                    'body' => ['token' => 'installation-token'],
                    'headers' => ['status-code' => 201],
                ];
            }
        };

        $adapter->initializeVariables('1234', $encode($pem), '5678');

        $jwt = substr((string) $adapter->captured['headers']['Authorization'], \strlen('Bearer '));
        [$header, $payload, $signature] = explode('.', $jwt);
        $verified = openssl_verify(
            $header . '.' . $payload,
            base64_decode(strtr($signature, '-_', '+/')),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        $this->assertSame(1, $verified);
    }

    public function testInitializeVariablesKeepsClientIdIssuerAsString(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($keyPair);
        openssl_pkey_export($keyPair, $pem);

        $adapter = new class (new Cache(new None())) extends GitHub {
            /** @var array<string, mixed> */
            public array $captured = [];

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $this->captured = ['headers' => $headers];

                return [
                    'body' => ['token' => 'installation-token'],
                    'headers' => ['status-code' => 201],
                ];
            }
        };

        $adapter->initializeVariables('1234', $pem, 'Iv1.0123456789abcdef');

        $jwt = substr((string) $adapter->captured['headers']['Authorization'], \strlen('Bearer '));
        $payload = explode('.', $jwt)[1];
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame('Iv1.0123456789abcdef', $claims['iss']);
    }

    public function testSearchRepositoriesPrivateProfile(): void
    {
        $adapter = new class (new Cache(new None())) extends GitHub {
            protected string $jwtToken = 'app-token';
            protected string $installationId = '1234';

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                return match ($path) {
                    '/app/installations/1234' => ['body' => ['repository_selection' => 'all', 'account' => ['login' => 'Private-Owner']]],
                    // GitHub refuses to search owners whose profile is private
                    '/search/repositories' => ['headers' => ['status-code' => 422]],
                    '/installation/repositories' => ['body' => ['repositories' => [['id' => 1, 'name' => 'private-repository']], 'total_count' => 1]],
                    default => ['headers' => ['status-code' => 404]],
                };
            }
        };

        $result = $adapter->searchRepositories('private-owner', 1, 10);

        $this->assertSame(['items' => [['id' => 1, 'name' => 'private-repository']], 'total' => 1], $result);
    }

    public function testGetInstallationRepositoryScansPastThousandRepositories(): void
    {
        $adapter = new class (new Cache(new None())) extends GitHub {
            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $page = \is_int($params['page'] ?? null) ? $params['page'] : 1;
                $repositories = [];
                foreach (\range(1, $page <= 10 ? 100 : 1) as $index) {
                    $id = ($page - 1) * 100 + $index;
                    $repositories[] = ['id' => $id, 'name' => 'repository-' . $id];
                }

                return ['body' => ['repositories' => $repositories]];
            }
        };

        $this->assertSame(['id' => 1001, 'name' => 'repository-1001'], $adapter->getInstallationRepository('repository-1001'));

        $this->expectException(RepositoryNotFound::class);
        $adapter->getInstallationRepository('repository-1002');
    }

    public function testSearchRepositoriesUnknownOwner(): void
    {
        $adapter = new class (new Cache(new None())) extends GitHub {
            protected string $jwtToken = 'app-token';
            protected string $installationId = '1234';

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                return match ($path) {
                    '/app/installations/1234' => ['body' => ['repository_selection' => 'all', 'account' => ['login' => 'installation-owner']]],
                    '/search/repositories' => ['headers' => ['status-code' => 422]],
                    default => ['headers' => ['status-code' => 404]],
                };
            }
        };

        $result = $adapter->searchRepositories('unknown-owner', 1, 10);

        $this->assertSame(['items' => [], 'total' => 0], $result);
    }

    /**
     * @return \Iterator<string, array{callable(string): string}>
     */
    public static function encodedPemProvider(): \Iterator
    {
        yield 'base64' => [base64_encode(...)];
        yield 'wrapped base64' => [fn (string $pem): string => chunk_split(base64_encode($pem), 76, "\n")];
        yield 'escaped newlines' => [fn (string $pem): string => str_replace("\n", '\n', $pem)];
    }

    public function testGetOwnerNameForMissingInstallationThrowsOwnerNotFound(): void
    {
        $adapter = $this->installationResponse(404, ['message' => 'Not Found']);

        $this->expectException(OwnerNotFound::class);

        $adapter->getOwnerName('1234');
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('transientInstallationResponses')]
    public function testGetOwnerNameKeepsTransientFailuresRetryable(int $status, array $body): void
    {
        $adapter = $this->installationResponse($status, $body);

        try {
            $adapter->getOwnerName('1234');
            $this->fail('A failed installation lookup must throw');
        } catch (\Exception $error) {
            $this->assertNotInstanceOf(OwnerNotFound::class, $error);
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>}>
     */
    public static function transientInstallationResponses(): iterable
    {
        yield 'rate limited' => [403, ['message' => 'API rate limit exceeded']];
        yield 'too many requests' => [429, ['message' => 'You have exceeded a secondary rate limit']];
        yield 'server error' => [502, ['message' => 'Server Error']];
    }

    public function testGetOwnerNameForMissingInstallationHasNotFoundCode(): void
    {
        $adapter = $this->installationResponse(404, ['message' => 'Not Found']);

        try {
            $adapter->getOwnerName('1234');
            $this->fail('A missing installation must throw');
        } catch (OwnerNotFound $error) {
            $this->assertSame(404, $error->getCode());
            $this->assertSame("Installation '1234' was not found.", $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('failedInstallationResponses')]
    public function testGetOwnerNameFailureNamesTheGitHubStatus(int $status, array $body): void
    {
        $adapter = $this->installationResponse($status, $body + ['documentation_url' => 'https://docs.github.com/rest', 'secret' => 'leaked']);

        try {
            $adapter->getOwnerName('1234');
            $this->fail('A failed installation lookup must throw');
        } catch (\Exception $error) {
            $this->assertNotInstanceOf(OwnerNotFound::class, $error);
            $this->assertSame(0, $error->getCode());
            $this->assertStringContainsString('Status: ' . $status, $error->getMessage());
            $this->assertStringContainsString((string) json_encode($body['message']), $error->getMessage());
            $this->assertStringNotContainsString('missing account login', $error->getMessage());
            $this->assertStringNotContainsString('leaked', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, array{message: string}}>
     */
    public static function failedInstallationResponses(): iterable
    {
        yield 'expired jwt' => [401, ['message' => "'Expiration time' claim ('exp') must be a numeric value representing the future time at which the assertion expires"]];
        yield 'rate limited' => [403, ['message' => 'API rate limit exceeded']];
        yield 'too many requests' => [429, ['message' => 'You have exceeded a secondary rate limit']];
        yield 'server error' => [502, ['message' => 'Server Error']];
    }

    public function testGetOwnerNameWithoutAccountLoginFails(): void
    {
        $adapter = $this->installationResponse(200, ['account' => null]);

        try {
            $adapter->getOwnerName('1234');
            $this->fail('An installation without an account login must throw');
        } catch (\Exception $error) {
            $this->assertNotInstanceOf(OwnerNotFound::class, $error);
            $this->assertSame('Owner name retrieval response is missing account login.', $error->getMessage());
        }
    }

    public function testInitializeVariablesForMissingInstallationThrowsOwnerNotFound(): void
    {
        $adapter = $this->recordingAdapter(new Cache(new None()), 404, ['message' => 'Not Found'], $this->requestLog());

        try {
            $adapter->initializeVariables('1234', $this->generatePrivateKey()['pem'], '5678');
            $this->fail('A missing installation must throw');
        } catch (OwnerNotFound $error) {
            $this->assertSame(404, $error->getCode());
            $this->assertSame("Installation '1234' was not found.", $error->getMessage());
        }
    }

    public function testInitializeVariablesKeepsOtherTokenFailures(): void
    {
        $adapter = $this->recordingAdapter(new Cache(new None()), 403, ['message' => 'This installation has been suspended'], $this->requestLog());

        try {
            $adapter->initializeVariables('1234', $this->generatePrivateKey()['pem'], '5678');
            $this->fail('A refused access token must throw');
        } catch (\Exception $error) {
            $this->assertNotInstanceOf(OwnerNotFound::class, $error);
            $this->assertSame(403, $error->getCode());
            $this->assertStringContainsString('This installation has been suspended', $error->getMessage());
        }
    }

    public function testInitializeVariablesRejectsAnUnreadablePrivateKey(): void
    {
        $adapter = $this->recordingAdapter($this->cacheWithJwt(time() + 300), 200, [], $this->requestLog());

        $this->expectExceptionMessage('Failed to read the GitHub App private key');

        $adapter->initializeVariables('1234', 'not a key', '5678');
    }

    public function testJwtIsBackdatedForClockDrift(): void
    {
        $key = $this->generatePrivateKey();
        $requests = $this->requestLog();
        $adapter = $this->recordingAdapter(new Cache(new None()), 201, ['token' => 'installation-token'], $requests);

        $adapter->initializeVariables('1234', $key['pem'], '5678');

        $this->assertCount(1, $requests);
        $this->assertSame('/app/installations/1234/access_tokens', $requests->getArrayCopy()[0]['path']);
        $claims = $this->verifiedClaims($requests->getArrayCopy()[0]['headers'], $key['public']);
        $this->assertLessThanOrEqual(time() - 59, $claims['iat']);
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
        $this->assertGreaterThan(time() + 500, $claims['exp']);
    }

    /**
     * @param callable(GitHub): mixed $invoke
     */
    #[DataProvider('jwtCalls')]
    public function testJwtCallsResignAnExpiredCachedJwt(callable $invoke): void
    {
        $this->assertJwtCallResigns($invoke, time() - 10);
    }

    /**
     * @param callable(GitHub): mixed $invoke
     */
    #[DataProvider('jwtCalls')]
    public function testJwtCallsResignACachedJwtAboutToExpire(callable $invoke): void
    {
        $this->assertJwtCallResigns($invoke, time() + 30);
    }

    /**
     * @param callable(GitHub): mixed $invoke
     */
    #[DataProvider('jwtCalls')]
    public function testJwtCallsResignOnceTheirOwnJwtAges(callable $invoke): void
    {
        $key = $this->generatePrivateKey();
        $requests = $this->requestLog();
        $adapter = $this->recordingAdapter(new Cache(new None()), 201, [
            'token' => 'installation-token',
            'expires_at' => '2026-10-27T16:00:00Z',
            'repository_selection' => 'all',
            'account' => ['login' => 'appwrite'],
        ], $requests);
        $adapter->initializeVariables('1234', $key['pem'], '5678');
        $first = $this->verifiedClaims($requests->getArrayCopy()[0]['headers'], $key['public']);

        $invoke($adapter);
        $this->assertSame($requests->getArrayCopy()[0]['headers'], $requests->getArrayCopy()[1]['headers'], 'A fresh JWT must be reused');

        $expiry = new \ReflectionProperty(GitHub::class, 'jwtExpiresAt');
        $expiry->setValue($adapter, time() + GitHub::GITHUB_APP_JWT_REFRESH_MARGIN - 1);
        $invoke($adapter);

        $claims = $this->verifiedClaims($requests->getArrayCopy()[2]['headers'], $key['public']);
        $this->assertGreaterThan(time() + GitHub::GITHUB_APP_JWT_REFRESH_MARGIN, $expiry->getValue($adapter), 'An aged JWT must be signed afresh');
        $this->assertSame($expiry->getValue($adapter), $claims['exp']);
        $this->assertGreaterThanOrEqual($first['exp'], $claims['exp']);
    }

    /**
     * @return iterable<string, array{callable(GitHub): mixed}>
     */
    public static function jwtCalls(): iterable
    {
        yield 'getOwnerName' => [fn (GitHub $adapter): string => $adapter->getOwnerName('1234')];
        yield 'hasAccessToAllRepositories' => [fn (GitHub $adapter): bool => $adapter->hasAccessToAllRepositories()];
        yield 'createAccessToken' => [fn (GitHub $adapter): array => $adapter->createAccessToken()];
    }

    /**
     * @param callable(GitHub): mixed $invoke
     */
    private function assertJwtCallResigns(callable $invoke, int $cachedExpiry): void
    {
        $key = $this->generatePrivateKey();
        $requests = $this->requestLog();
        $adapter = $this->recordingAdapter($this->cacheWithJwt($cachedExpiry), 201, [
            'token' => 'scoped-token',
            'expires_at' => '2026-10-27T16:00:00Z',
            'repository_selection' => 'all',
            'account' => ['login' => 'appwrite'],
        ], $requests);

        $adapter->initializeVariables('1234', $key['pem'], '5678');
        $this->assertCount(0, $requests, 'A cached access token must be reused');

        $invoke($adapter);

        $this->assertCount(1, $requests);
        $claims = $this->verifiedClaims($requests->getArrayCopy()[0]['headers'], $key['public']);
        $this->assertGreaterThan(time() + GitHub::GITHUB_APP_JWT_REFRESH_MARGIN, $claims['exp']);
        $this->assertSame(5678, $claims['iss']);
    }

    private function cacheWithJwt(int $expiry): Cache
    {
        $stale = $this->generatePrivateKey();
        $jwt = new \Ahc\Jwt\JWT($stale['key'], 'RS256')->encode(['iat' => $expiry - 600, 'exp' => $expiry, 'iss' => 5678]);

        $cache = new Cache(new Memory());
        $cache->save('1234', (string) json_encode(['jwtToken' => $jwt, 'accessToken' => 'installation-token']));

        return $cache;
    }

    /**
     * @return array{key: \OpenSSLAsymmetricKey, pem: string, public: string}
     */
    private function generatePrivateKey(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->assertIsString($pem);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertIsString($details['key']);

        return ['key' => $key, 'pem' => $pem, 'public' => $details['key']];
    }

    /**
     * @param array<mixed> $headers
     * @return array{iat: int, exp: int, iss: mixed}
     */
    private function verifiedClaims(array $headers, string $publicKey): array
    {
        $authorization = $headers['Authorization'] ?? null;
        $this->assertIsString($authorization);
        $jwt = substr($authorization, \strlen('Bearer '));
        [$header, $payload, $signature] = array_pad(explode('.', $jwt), 3, '');
        $verified = openssl_verify($header . '.' . $payload, base64_decode(strtr($signature, '-_', '+/')), $publicKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $verified, 'The JWT must be signed with the app key');

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertIsArray($claims);
        $this->assertIsInt($claims['iat'] ?? null);
        $this->assertIsInt($claims['exp'] ?? null);

        return ['iat' => $claims['iat'], 'exp' => $claims['exp'], 'iss' => $claims['iss'] ?? null];
    }

    /**
     * @return \ArrayObject<int, array{method: string, path: string, headers: array<mixed>, params: array<mixed>}>
     */
    private function requestLog(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * @param array<string, mixed> $body
     * @param \ArrayObject<int, array{method: string, path: string, headers: array<mixed>, params: array<mixed>}> $requests receives every request the adapter sends
     */
    private function recordingAdapter(Cache $cache, int $status, array $body, \ArrayObject $requests): GitHub
    {
        return new class ($cache, $status, $body, $requests) extends GitHub {
            /**
             * @param array<string, mixed> $body
             * @param \ArrayObject<int, array{method: string, path: string, headers: array<mixed>, params: array<mixed>}> $requests
             */
            public function __construct(Cache $cache, private readonly int $status, private readonly array $body, private readonly \ArrayObject $requests)
            {
                parent::__construct($cache);
            }

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'params' => $params];

                return ['body' => $this->body, 'headers' => ['status-code' => $this->status]];
            }
        };
    }

    public function testGetOwnerNameReturnsInstallationLogin(): void
    {
        $adapter = $this->installationResponse(200, ['account' => ['login' => 'appwrite']]);

        $this->assertSame('appwrite', $adapter->getOwnerName('1234'));
    }

    public function testCreateAccessTokenLimitsItToTheRepositories(): void
    {
        $captured = new \stdClass();
        $adapter = $this->accessTokenResponse(201, ['token' => 'ghs_scoped', 'expires_at' => '2026-10-27T16:00:00Z'], $captured);

        $token = $adapter->createAccessToken([42]);

        $this->assertSame(['token' => 'ghs_scoped', 'expiresAt' => '2026-10-27T16:00:00Z'], $token);
        $this->assertSame([
            'method' => 'POST',
            'path' => '/app/installations/1234/access_tokens',
            'headers' => ['Authorization' => 'Bearer app-token'],
            'params' => ['repository_ids' => [42]],
        ], $captured->request);
    }

    public function testCreateAccessTokenWithoutRepositoriesCoversTheInstallation(): void
    {
        $captured = new \stdClass();
        $adapter = $this->accessTokenResponse(201, ['token' => 'ghs_all', 'expires_at' => '2026-10-27T16:00:00Z'], $captured);

        $adapter->createAccessToken();

        $this->assertSame([
            'method' => 'POST',
            'path' => '/app/installations/1234/access_tokens',
            'headers' => ['Authorization' => 'Bearer app-token'],
            'params' => [],
        ], $captured->request);
    }

    public function testCreateAccessTokenThrowsWhenGitHubRefuses(): void
    {
        $adapter = $this->accessTokenResponse(422, ['message' => 'There is at least one repository that does not exist or is not accessible to the parent installation.', 'token' => 'leaked'], new \stdClass());

        try {
            $adapter->createAccessToken([42]);
            $this->fail('Expected the refusal to throw.');
        } catch (\Exception $error) {
            $this->assertSame(422, $error->getCode());
            $this->assertStringContainsString('not accessible', $error->getMessage());
            $this->assertStringNotContainsString('leaked', $error->getMessage());
        }
    }

    public function testGetCollaboratorPermissionReturnsThePermission(): void
    {
        $captured = new \stdClass();
        $adapter = $this->accessTokenResponse(200, ['permission' => 'write', 'role_name' => 'maintain'], $captured);

        $this->assertSame('write', $adapter->getCollaboratorPermission('appwrite', 'appwrite', 'octocat'));
        $this->assertIsArray($captured->request);
        $this->assertSame('GET', $captured->request['method']);
        $this->assertSame('/repos/appwrite/appwrite/collaborators/octocat/permission', $captured->request['path']);
    }

    public function testGetCollaboratorPermissionIsNoneForAnUnknownUser(): void
    {
        $adapter = $this->accessTokenResponse(404, ['message' => 'Not Found'], new \stdClass());

        $this->assertSame('none', $adapter->getCollaboratorPermission('appwrite', 'appwrite', 'ghost'));
    }

    public function testGetCollaboratorPermissionThrowsWhenGitHubFails(): void
    {
        $adapter = $this->accessTokenResponse(500, ['message' => 'Server Error'], new \stdClass());

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(500);
        $adapter->getCollaboratorPermission('appwrite', 'appwrite', 'octocat');
    }

    /**
     * @param array<string, mixed> $body
     * @param \stdClass $captured its `request` receives the request the adapter sent
     */
    private function accessTokenResponse(int $status, array $body, \stdClass $captured): GitHub
    {
        return new class ($status, $body, $captured) extends GitHub {
            protected string $jwtToken = 'app-token';

            protected string $installationId = '1234';

            /**
             * @param array<string, mixed> $body
             */
            public function __construct(private readonly int $status, private readonly array $body, private readonly \stdClass $captured)
            {
                parent::__construct(new Cache(new None()));
            }

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                $this->captured->request = ['method' => $method, 'path' => $path, 'headers' => $headers, 'params' => $params];

                return ['body' => $this->body, 'headers' => ['status-code' => $this->status]];
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function installationResponse(int $status, array $body): GitHub
    {
        return new class ($status, $body) extends GitHub {
            protected string $jwtToken = 'app-token';

            /**
             * @param array<string, mixed> $body
             */
            public function __construct(private readonly int $status, private readonly array $body)
            {
                parent::__construct(new Cache(new None()));
            }

            protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
            {
                return ['body' => $this->body, 'headers' => ['status-code' => $this->status]];
            }
        };
    }
}
