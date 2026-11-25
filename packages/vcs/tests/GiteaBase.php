<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

/**
 * Forgejo and Gogs are Gitea forks and deliver Gitea-shaped payloads, so all
 * three read the same fixtures.
 */
abstract class GiteaBase extends Base
{
    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        $repositoryUrl = 'http://gitea:3000/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

        return (string) json_encode([
            'ref' => 'refs/heads/' . $branch,
            'before' => 'abc123',
            'after' => self::EVENT_COMMIT_HASH,
            'created' => $created,
            'deleted' => $deleted,
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'html_url' => $repositoryUrl,
                'owner' => ['login' => self::EVENT_OWNER],
            ],
            'sender' => [
                'login' => self::EVENT_AUTHOR_NAME,
                'html_url' => 'http://gitea:3000/pusher-user',
                'avatar_url' => 'http://gitea:3000/avatars/pusher',
            ],
            'head_commit' => [
                'id' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'url' => $repositoryUrl . '/commit/' . self::EVENT_COMMIT_HASH,
                'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
            ],
            'commits' => [[
                'id' => self::EVENT_COMMIT_HASH,
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ]],
        ]);
    }

    protected function pullRequestPayload(bool $external = false): string
    {
        $repositoryUrl = 'http://gitea:3000/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;
        $headRepository = $external
            ? 'someone-else/forked-repo'
            : self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

        return (string) json_encode([
            'action' => 'opened',
            'number' => self::EVENT_PULL_REQUEST_NUMBER,
            'pull_request' => [
                'id' => 1,
                'number' => self::EVENT_PULL_REQUEST_NUMBER,
                'state' => 'open',
                'title' => 'Test PR',
                'head' => [
                    'ref' => self::EVENT_HEAD_BRANCH,
                    'sha' => self::EVENT_COMMIT_HASH,
                    'repo' => ['full_name' => $headRepository],
                    'user' => ['login' => self::EVENT_OWNER],
                ],
                'base' => [
                    'ref' => self::$defaultBranch,
                    'sha' => 'abc123',
                    'user' => ['login' => self::EVENT_OWNER],
                ],
                'user' => ['login' => self::EVENT_OWNER, 'avatar_url' => 'http://gitea:3000/avatars/pr-author'],
            ],
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'html_url' => $repositoryUrl,
                'owner' => ['login' => self::EVENT_OWNER],
            ],
            'sender' => ['login' => self::EVENT_OWNER, 'html_url' => 'http://gitea:3000/' . self::EVENT_OWNER],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function commentPayload(array $overrides = []): string
    {
        return (string) json_encode([
            'action' => 'created',
            'is_pull' => true,
            'issue' => ['number' => self::EVENT_PULL_REQUEST_NUMBER, 'user' => ['login' => 'author']],
            'pull_request' => ['number' => self::EVENT_PULL_REQUEST_NUMBER, 'user' => ['login' => 'author']],
            'comment' => [
                'id' => 99,
                'body' => '@appwrite review',
                'html_url' => 'http://gitea:3000/o/r/pulls/42#issuecomment-99',
                'user' => ['id' => 5, 'login' => 'octo'],
            ],
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'html_url' => 'http://gitea:3000/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'owner' => ['login' => self::EVENT_OWNER],
            ],
            ...$overrides,
        ]);
    }

    public function testGetEventPullRequestComment(): void
    {
        $events = $this->vcsAdapter->getEvents('issue_comment', $this->commentPayload());

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('created', $event['action']);
        $this->assertSame(self::EVENT_REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::EVENT_OWNER, $event['owner']);
        $this->assertSame(self::EVENT_PULL_REQUEST_NUMBER, $event['pullRequestNumber']);
        $this->assertSame('author', $event['pullRequestAuthor']);
        $this->assertSame('99', $event['commentId']);
        // Review thread replies look the same; the caller looks their thread up
        $this->assertSame('issue', $event['commentKind']);
        $this->assertSame('@appwrite review', $event['commentBody']);
        $this->assertSame('octo', $event['commentAuthor']);
        $this->assertSame('5', $event['commentAuthorId']);
        $this->assertSame('User', $event['commentAuthorType']);
    }

    public function testGetEventIgnoresOtherComments(): void
    {
        $this->assertSame([], $this->vcsAdapter->getEvents('issue_comment', $this->commentPayload(['is_pull' => false])));
        $this->assertSame([], $this->vcsAdapter->getEvents('issue_comment', $this->commentPayload(['action' => 'edited'])));
    }
}
