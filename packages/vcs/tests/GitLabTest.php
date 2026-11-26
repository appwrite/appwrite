<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitLab;

final class GitLabTest extends Base
{
    #[\Override]
    protected static string $eventHeader = 'x-gitlab-event';
    #[\Override]
    protected static string $signatureHeader = 'x-gitlab-token';
    #[\Override]
    protected static string $pushEventName = 'Push Hook';
    #[\Override]
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

    /**
     * @param array<string, mixed> $note
     */
    private function notePayload(array $note): string
    {
        return (string) json_encode([
            'object_kind' => 'note',
            'user' => ['id' => 7, 'username' => 'octo'],
            'project' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'namespace' => self::EVENT_OWNER,
                'web_url' => 'http://example.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
            ],
            'object_attributes' => [
                'id' => 1244,
                'note' => '@appwrite review',
                'noteable_type' => 'MergeRequest',
                'system' => false,
                'discussion_id' => 'd0c1e2f3',
                'type' => null,
                'action' => 'create',
                'url' => 'http://example.com/mr/42#note_1244',
                ...$note,
            ],
            'merge_request' => ['iid' => self::EVENT_PULL_REQUEST_NUMBER, 'author_id' => 7],
        ]);
    }

    public function testGetEventMergeRequestComment(): void
    {
        $events = $this->vcsAdapter->getEvents('Note Hook', $this->notePayload([]));

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('created', $event['action']);
        $this->assertSame(self::EVENT_REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::EVENT_OWNER, $event['owner']);
        $this->assertSame(self::EVENT_PULL_REQUEST_NUMBER, $event['pullRequestNumber']);
        $this->assertSame('octo', $event['pullRequestAuthor']);
        $this->assertSame('1244', $event['commentId']);
        $this->assertSame('issue', $event['commentKind']);
        $this->assertSame('', $event['commentThreadId']);
        $this->assertSame('', $event['commentReplyToId']);
        $this->assertSame('@appwrite review', $event['commentBody']);
        $this->assertSame('http://example.com/mr/42#note_1244', $event['commentUrl']);
        $this->assertSame('octo', $event['commentAuthor']);
        $this->assertSame('7', $event['commentAuthorId']);
        $this->assertSame('User', $event['commentAuthorType']);
    }

    public function testGetEventMergeRequestDiffComment(): void
    {
        $events = $this->vcsAdapter->getEvents('Note Hook', $this->notePayload(['type' => 'DiffNote']));

        // GitLab keys a diff thread by its discussion, and does not say which note opened it
        $this->assertSame('review', $events[0]['commentKind']);
        $this->assertSame('d0c1e2f3', $events[0]['commentThreadId']);
        $this->assertSame('d0c1e2f3', $events[0]['commentReplyToId']);
    }

    public function testGetEventIgnoresOtherNotes(): void
    {
        $this->assertSame([], $this->vcsAdapter->getEvents('Note Hook', $this->notePayload(['noteable_type' => 'Issue'])));
        $this->assertSame([], $this->vcsAdapter->getEvents('Note Hook', $this->notePayload(['system' => true])));
        $this->assertSame([], $this->vcsAdapter->getEvents('Note Hook', $this->notePayload(['action' => 'update'])));
    }

    public function testGetEventMergeRequestAuthor(): void
    {
        $payload = (string) json_encode([
            'object_kind' => 'merge_request',
            'user' => ['id' => 7, 'username' => 'octo'],
            'project' => ['id' => 1, 'name' => 'r', 'namespace' => 'o', 'web_url' => 'http://example.com/o/r'],
            'object_attributes' => ['iid' => 1, 'action' => 'update', 'author_id' => 8],
        ]);

        // The hook names the author by id only, so someone else's update leaves it unknown
        $this->assertSame('', $this->vcsAdapter->getEvents('Merge Request Hook', $payload)[0]['pullRequestAuthor']);
    }

    /**
     * @return \Iterator<string, array{string, array<string, mixed>}>
     */
    public static function providerProjectPaths(): \Iterator
    {
        $project = [
            'id' => 89442539,
            'name' => 'Magic Portfolio',
            'namespace' => 'Jane Doe',
            'path_with_namespace' => 'jane-doe/magic-portfolio',
            'web_url' => 'https://gitlab.com/jane-doe/magic-portfolio',
        ];

        yield 'push' => ['Push Hook', [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'checkout_sha' => 'abc123',
            'project' => $project,
            'commits' => [],
        ]];
        yield 'merge request' => ['Merge Request Hook', [
            'object_kind' => 'merge_request',
            'project' => $project,
            'object_attributes' => ['iid' => 1, 'action' => 'open', 'source_branch' => 'feature', 'target_branch' => 'main'],
        ]];
        yield 'note' => ['Note Hook', [
            'object_kind' => 'note',
            'project' => $project,
            'object_attributes' => ['id' => 1, 'note' => '@appwrite review', 'noteable_type' => 'MergeRequest', 'action' => 'create'],
            'merge_request' => ['iid' => 1],
        ]];
    }

    /**
     * The API addresses a project by its paths, not the display names a webhook also carries.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('providerProjectPaths')]
    public function testGetEventProjectPaths(string $event, array $body): void
    {
        $payload = json_encode($body);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvents($event, $payload)[0];

        $this->assertSame('jane-doe', $result['owner']);
        $this->assertSame('magic-portfolio', $result['repositoryName']);
    }

    public function testGetEventSubgroupProjectPath(): void
    {
        $payload = json_encode([
            'object_kind' => 'merge_request',
            'project' => ['id' => 1, 'name' => 'App', 'namespace' => 'Sub', 'path_with_namespace' => 'group/sub/app'],
            'object_attributes' => ['iid' => 1, 'action' => 'open', 'source_branch' => 'feature', 'target_branch' => 'main'],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvents('Merge Request Hook', $payload)[0];

        $this->assertSame('group/sub', $result['owner']);
        $this->assertSame('app', $result['repositoryName']);
    }
}
