<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitHub;

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
        $this->assertSame('5678', $claims['iss']);
    }
}
