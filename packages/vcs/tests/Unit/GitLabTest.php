<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitLab;

final class GitLabTest extends Base
{
    protected static string $eventHeader = 'x-gitlab-event';
    protected static string $signatureHeader = 'x-gitlab-token';
    protected static string $pushEventName = 'Push Hook';
    protected static string $pullRequestEventName = 'Merge Request Hook';

    protected function createAdapter(): GitLab
    {
        return new GitLab(new Cache(new None()));
    }
    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return $secret;
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        $blank = str_repeat('0', 40);
        $repositoryUrl = 'http://example.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

        return (string) json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/' . $branch,
            // GitLab signals a created or deleted branch with an all-zero sha
            'before' => $created ? $blank : 'abc123',
            'after' => $deleted ? $blank : self::EVENT_COMMIT_HASH,
            'checkout_sha' => $deleted ? '' : self::EVENT_COMMIT_HASH,
            'user_avatar' => 'http://example.com/avatar.png',
            'project' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'namespace' => self::EVENT_OWNER,
                'web_url' => $repositoryUrl,
            ],
            'commits' => $deleted ? [] : [[
                'id' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'url' => $repositoryUrl . '/-/commit/' . self::EVENT_COMMIT_HASH,
                'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ]],
        ]);
    }

    protected function pullRequestPayload(bool $external = false): string
    {
        return (string) json_encode([
            'object_kind' => 'merge_request',
            'project' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'namespace' => self::EVENT_OWNER,
                'web_url' => 'http://example.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
            ],
            'object_attributes' => [
                'iid' => self::EVENT_PULL_REQUEST_NUMBER,
                'title' => 'Test MR',
                // GitLab calls it 'open' and normalizes to 'opened'
                'action' => 'open',
                'source_branch' => self::EVENT_HEAD_BRANCH,
                'target_branch' => self::$defaultBranch,
                'source_project_id' => $external ? 456 : (int) self::EVENT_REPOSITORY_ID,
                'target_project_id' => (int) self::EVENT_REPOSITORY_ID,
                'url' => 'http://example.com/mr/' . self::EVENT_PULL_REQUEST_NUMBER,
                'last_commit' => [
                    'id' => self::EVENT_COMMIT_HASH,
                    'message' => self::EVENT_COMMIT_MESSAGE,
                    'url' => 'http://example.com/commit/' . self::EVENT_COMMIT_HASH,
                    'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
                ],
            ],
        ]);
    }

    public function testGetEventPushMatchesCheckoutSha(): void
    {
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'checkout_sha' => 'def456',
            'project' => [
                'name' => 'test-repo',
                'namespace' => 'test-org',
            ],
            'commits' => [
                [
                    'id' => 'abc123',
                    'message' => 'Older commit',
                    'url' => 'http://example.com/commit/abc123',
                    'author' => ['name' => 'Old Author'],
                ],
                [
                    'id' => 'def456',
                    'message' => 'Head commit',
                    'url' => 'http://example.com/commit/def456',
                    'author' => ['name' => 'Head Author'],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $events = $this->vcsAdapter->getEvents('Push Hook', $payload);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertIsArray($result);
        $this->assertSame('def456', $result['commitHash']);
        $this->assertSame('Head Author', $result['headCommitAuthorName']);
        $this->assertSame('Head commit', $result['headCommitMessage']);
        $this->assertSame('http://example.com/commit/def456', $result['headCommitUrl']);
    }

    public function testGetEventPullRequestActionMapping(): void
    {
        foreach (['open' => 'opened', 'reopen' => 'reopened', 'update' => 'synchronize', 'close' => 'closed', 'merge' => 'closed'] as $native => $mapped) {
            $payload = json_encode([
                'object_kind' => 'merge_request',
                'project' => ['id' => 1, 'name' => 'r', 'namespace' => 'o', 'web_url' => 'http://example.com/o/r'],
                'object_attributes' => ['iid' => 1, 'action' => $native, 'source_branch' => 'f', 'target_branch' => 'main'],
            ]);

            if ($payload === false) {
                $this->fail('Failed to encode JSON payload');
            }

            $events = $this->vcsAdapter->getEvents('Merge Request Hook', $payload);
            $this->assertCount(1, $events);
            $result = $events[0];
            $this->assertSame($mapped, $result['action'], "native action '{$native}' should map to '{$mapped}'");
        }
    }
}
