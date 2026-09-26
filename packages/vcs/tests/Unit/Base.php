<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;

/**
 * The half of the adapter contract that needs no provider: reading a webhook
 * delivery and verifying its signature. Every provider answers it from a
 * payload the subclass builds, so the suite runs on a bare host.
 */
abstract class Base extends TestCase
{
    /**
     * Facts the webhook payload builders below carry, asserted back out of the
     * normalized event. Bitbucket overrides the repository id, having none.
     */
    protected const EVENT_REPOSITORY_ID = '123';

    protected const EVENT_REPOSITORY_NAME = 'test-repo';

    protected const EVENT_OWNER = 'test-owner';

    protected const EVENT_COMMIT_HASH = 'def456';

    protected const EVENT_COMMIT_MESSAGE = 'Test commit message';

    protected const EVENT_AUTHOR_NAME = 'Test Author';

    protected const EVENT_AUTHOR_EMAIL = 'author@example.com';

    protected const EVENT_HEAD_BRANCH = 'feature-branch';

    protected const EVENT_PULL_REQUEST_NUMBER = 42;

    protected Git $vcsAdapter;

    protected static string $defaultBranch = 'main';

    /**
     * Scopes the provider accepts webhooks at. Only GitHub registers them
     * once per installation as well as per repository.
     *
     * @var array<string>
     */
    protected static array $supportedWebhookScopes = [Git::WEBHOOK_SCOPE_REPOSITORY];

    /**
     * Names the provider uses for the events it delivers.
     */
    protected static string $pushEventName = 'push';

    protected static string $pullRequestEventName = 'pull_request';

    /**
     * Headers the provider sends its webhook event type and signature under.
     */
    protected static string $eventHeader = '';

    protected static string $signatureHeader = '';

    /**
     * Whether a push event names the files it touched. Bitbucket's payload
     * carries no file lists at all.
     */
    protected static bool $reportsAffectedFilesInPushEvent = true;

    /**
     * Build the adapter under test. It stays uninitialized against any
     * provider - nothing here is allowed to reach the network.
     */
    abstract protected function createAdapter(): Git;

    /**
     * Sign a payload the way the provider signs its webhooks.
     */
    abstract protected function signWebhookPayload(string $payload, string $secret): string;

    /**
     * Build a push payload shaped the way this provider sends one, carrying the
     * EVENT_* facts above.
     *
     * @param array<string> $added
     * @param array<string> $removed
     * @param array<string> $modified
     */
    abstract protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string;

    /**
     * Build a pull request payload shaped the way this provider sends one,
     * opening EVENT_HEAD_BRANCH against the default branch.
     */
    abstract protected function pullRequestPayload(bool $external = false): string;

    protected function setUp(): void
    {
        $this->vcsAdapter = $this->createAdapter();
    }

    public function testWebhookHeaderNames(): void
    {
        $this->assertSame(static::$eventHeader, $this->vcsAdapter->getEventHeaderName());
        $this->assertSame(static::$signatureHeader, $this->vcsAdapter->getSignatureHeaderName());
    }

    public function testGetSupportedWebhookScopes(): void
    {
        $this->assertSame(static::$supportedWebhookScopes, $this->vcsAdapter->getSupportedWebhookScopes());
    }
    public function testValidateWebhookEvent(): void
    {
        $payload = '{"object_kind":"push","action":"push"}';
        $secret = 'my-webhook-secret';

        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $secret), $secret),
        );
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, 'not-the-signature', $secret));
        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, 'another-secret'), $secret),
        );
    }
    public function testGetEventPush(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, ['file1.txt'], ['file2.txt'], ['file3.txt']),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame(static::$defaultBranch, $result['branch']);
        $this->assertSame(static::EVENT_REPOSITORY_ID, $result['repositoryId']);
        $this->assertSame(self::EVENT_REPOSITORY_NAME, $result['repositoryName']);
        $this->assertSame(self::EVENT_OWNER, $result['owner']);
        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertSame(self::EVENT_COMMIT_MESSAGE, $result['headCommitMessage']);
        $this->assertSame(self::EVENT_AUTHOR_NAME, $result['headCommitAuthorName']);
        $this->assertSame(self::EVENT_AUTHOR_EMAIL, $result['headCommitAuthorEmail']);
        $this->assertNotEmpty($result['headCommitUrl']);
        $this->assertNotEmpty($result['repositoryUrl']);
        $this->assertNotEmpty($result['branchUrl']);
        $this->assertFalse($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
        $this->assertEqualsCanonicalizing(
            static::$reportsAffectedFilesInPushEvent ? ['file1.txt', 'file2.txt', 'file3.txt'] : [],
            $result['affectedFiles'],
        );
    }

    public function testGetEventPushDetectsBranchCreated(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, created: true),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertTrue($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
    }

    public function testGetEventPushDetectsBranchDeleted(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, deleted: true),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertFalse($result['branchCreated']);
        $this->assertTrue($result['branchDeleted']);
    }

    public function testGetEventPullRequest(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload());
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('opened', $result['action']);
        $this->assertSame(self::EVENT_HEAD_BRANCH, $result['branch']);
        $this->assertSame(self::EVENT_PULL_REQUEST_NUMBER, $result['pullRequestNumber']);
        $this->assertSame(static::EVENT_REPOSITORY_ID, $result['repositoryId']);
        $this->assertSame(self::EVENT_REPOSITORY_NAME, $result['repositoryName']);
        $this->assertSame(self::EVENT_OWNER, $result['owner']);
        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertFalse($result['external']);
    }

    public function testGetEventPullRequestDetectsExternal(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload(external: true));
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertTrue($result['external']);
    }

    public function testGetEventInvalidPayload(): void
    {
        $this->expectException(Exception::class);
        $this->vcsAdapter->getEvents('push', 'invalid json');
    }

    public function testGetEventUnsupportedEvent(): void
    {
        $payload = json_encode(['test' => 'data']);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvents('unsupported_event', $payload);

        $this->assertEmpty($result);
    }

    #[DataProvider('rootDirectories')]
    public function testGenerateCloneCommandSelectsTheRootDirectory(string $rootDirectory, string $pattern): void
    {
        $command = $this->vcsAdapter->generateCloneCommand('owner', 'repo', 'main', Git::CLONE_TYPE_BRANCH, '/tmp/clone', $rootDirectory);

        $this->assertStringContainsString(escapeshellarg($pattern), $command);
    }

    /**
     * Git matches a sparse-checkout pattern gitignore-style, so a './' prefix
     * looks for a directory literally named '.' and checks out nothing.
     */
    public static function rootDirectories(): \Iterator
    {
        yield 'repository root' => ['', '*'];
        yield 'dot' => ['.', '*'];
        yield 'dot slash' => ['./', '*'];
        yield 'slash' => ['/', '*'];
        yield 'bare' => ['docs', 'docs'];
        yield 'trailing slash' => ['docs/', 'docs'];
        yield 'dot slash prefix' => ['./docs', 'docs'];
        yield 'dot slash prefix and trailing slash' => ['./docs/', 'docs'];
        yield 'nested' => ['./astro/starter', 'astro/starter'];
        // A directory named '0' is a real path, not a root sentinel.
        yield 'zero' => ['0', '0'];
    }
}
