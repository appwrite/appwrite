<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\OwnerNotFound;

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
