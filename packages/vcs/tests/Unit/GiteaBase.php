<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

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
}
