<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Reviews;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Query;
use Utopia\System\System;

final class ReviewsConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public string $providerInstallationId = '42954928'; // appwrite-test
    public string $providerRepositoryId = '943139433'; // svelte-starter (public), reviewed by no other suite

    private static array $cachedInstallationId = [];

    protected function setupInstallation(): string
    {
        // Same gate as the VCSGitHub suite: without the GitHub App there is no installation to use.
        if (System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled') {
            $this->markTestSkipped('VCS is not enabled.');
        }

        $projectId = $this->getProject()['$id'];

        if (!empty(self::$cachedInstallationId[$projectId])) {
            return self::$cachedInstallationId[$projectId];
        }

        $response = $this->client->call(Client::METHOD_GET, '/mock/github/callback', array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'providerInstallationId' => $this->providerInstallationId,
            'projectId' => $projectId,
        ]);

        self::$cachedInstallationId[$projectId] = $response['body']['installationId'];

        return self::$cachedInstallationId[$projectId];
    }

    public function testReviewLifecycle(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();
        $pullRequest = \random_int(1000, 9_999_999);
        $firstHead = \str_repeat('a', 40);
        $secondHead = \str_repeat('b', 40);

        /**
         * Test for SUCCESS
         */

        // Turn code review on
        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);
        $this->assertSame($installationId, $repository['body']['installationId']);
        $this->assertSame($this->providerRepositoryId, $repository['body']['providerRepositoryId']);
        $repositoryId = $repository['body']['$id'];

        $repositories = $this->client->call(Client::METHOD_GET, '/reviews/repositories', $this->projectHeaders());
        $this->assertSame(200, $repositories['headers']['status-code']);
        $this->assertSame(1, $repositories['body']['total']);
        $this->assertSame($repositoryId, $repositories['body']['repositories'][0]['$id']);

        // Opening the pull request queues a review
        $event = $this->sendPullRequestEvent('opened', $pullRequest, $firstHead);
        $this->assertSame(200, $event['headers']['status-code']);

        $reviews = $this->listReviews($pullRequest);
        $this->assertSame(1, $reviews['body']['total']);
        $review = $reviews['body']['reviews'][0];
        $this->assertSame('queued', $review['status']);
        $this->assertSame('opened', $review['trigger']);
        $this->assertSame($repositoryId, $review['repositoryId']);
        $this->assertSame($firstHead, $review['providerCommitHash']);
        $reviewId = $review['$id'];

        // A push while it is still queued moves the queued review to the new head
        $this->sendPullRequestEvent('synchronize', $pullRequest, $secondHead);

        $reviews = $this->listReviews($pullRequest);
        $this->assertSame(1, $reviews['body']['total']);
        $this->assertSame($reviewId, $reviews['body']['reviews'][0]['$id']);
        $this->assertSame($secondHead, $reviews['body']['reviews'][0]['providerCommitHash']);

        // The review service starts, reports trace and usage, and completes it
        $started = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'processing',
            'headSha' => $secondHead,
            'baseSha' => \str_repeat('c', 40),
        ]);
        $this->assertSame(204, $started['headers']['status-code']);

        $events = $this->callService(Client::METHOD_POST, '/reviews/' . $reviewId . '/events', [
            'projectId' => $projectId,
            'events' => [
                ['type' => 'tool_call', 'at' => '2026-10-27T15:00:00.000Z', 'data' => ['tool' => 'read_file']],
            ],
        ]);
        $this->assertSame(204, $events['headers']['status-code']);

        $usage = $this->callService(Client::METHOD_POST, '/reviews/' . $reviewId . '/usage', [
            'projectId' => $projectId,
            'usage' => [
                ['role' => 'review', 'provider' => 'anthropic', 'model' => 'claude', 'inputTokens' => 1200, 'outputTokens' => 300, 'durationMs' => 4100],
            ],
        ]);
        $this->assertSame(204, $usage['headers']['status-code']);

        // A push while it runs queues a second review instead of moving the running one
        $this->sendPullRequestEvent('synchronize', $pullRequest, \str_repeat('d', 40));
        $queued = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $queued['body']['total']);
        $this->assertNotSame($reviewId, $queued['body']['reviews'][0]['$id']);

        $completed = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'completed',
            'verdict' => 'comment',
            'tier' => 'B',
            'summary' => 'One missing null check.',
            'providerReviewId' => 1834620151,
            'findings' => [[
                'path' => 'src/index.ts',
                'startLine' => 12,
                'endLine' => 14,
                'severity' => 'major',
                'category' => 'bug',
                'title' => 'Missing null check',
                'body' => '`user` can be null here.',
            ]],
        ]);
        $this->assertSame(204, $completed['headers']['status-code']);

        $review = $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders());
        $this->assertSame(200, $review['headers']['status-code']);
        $this->assertSame('completed', $review['body']['status']);
        $this->assertSame('comment', $review['body']['verdict']);
        $this->assertSame('B', $review['body']['tier']);
        $this->assertSame($secondHead, $review['body']['providerCommitHash']);
        $this->assertSame(\str_repeat('c', 40), $review['body']['providerBaseCommitHash']);
        $this->assertSame('1834620151', $review['body']['providerReviewId']);
        $this->assertNotEmpty($review['body']['startedAt']);
        $this->assertNotEmpty($review['body']['completedAt']);
        $this->assertCount(1, $review['body']['findings']);
        $this->assertSame('src/index.ts', $review['body']['findings'][0]['path']);
        $this->assertSame(12, $review['body']['findings'][0]['startLine']);

        /**
         * Test for FAILURE
         */

        // An ended review does not change again, and keeps its findings
        $again = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'processing',
            'headSha' => $secondHead,
            'baseSha' => \str_repeat('c', 40),
        ]);
        $this->assertSame(409, $again['headers']['status-code']);
        $this->assertSame('review_status_invalid', $again['body']['type']);

        $review = $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders());
        $this->assertSame('completed', $review['body']['status']);
        $this->assertCount(1, $review['body']['findings']);

        // Code review is on for the repository already
        $duplicate = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('review_repository_already_exists', $duplicate['body']['type']);

        // Turning it off stops queueing reviews
        $deleted = $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repositoryId, $this->projectHeaders());
        $this->assertSame(204, $deleted['headers']['status-code']);

        $otherPullRequest = $pullRequest + 1;
        $this->sendPullRequestEvent('opened', $otherPullRequest, $firstHead);
        $this->assertSame(0, $this->listReviews($otherPullRequest)['body']['total']);

        $repositories = $this->client->call(Client::METHOD_GET, '/reviews/repositories', $this->projectHeaders());
        $this->assertSame(0, $repositories['body']['total']);
    }

    public function testCommentChat(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();
        $pullRequest = \random_int(1000, 9_999_999);
        $handle = System::getEnv('_APP_VCS_GITHUB_APP_NAME', '');

        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);
        $repositoryId = $repository['body']['$id'];

        /**
         * Test for SUCCESS
         */

        // A reply in a review thread is queued without a mention; the service decides if it is for it
        $reply = \random_int(1_000_000, 9_999_999_999);
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $reply, 'Why is this needed?', replyTo: $reply - 1);
        $this->assertSame(204, $this->createChatUsage($repositoryId, $pullRequest, $reply)['headers']['status-code']);

        if ($handle !== '') {
            // A mention on the conversation is queued as a question
            $question = $reply + 1;
            $this->sendCommentEvent('issue_comment', $pullRequest, $question, '@' . $handle . ' what does this change?');
            $this->assertSame(204, $this->createChatUsage($repositoryId, $pullRequest, $question)['headers']['status-code']);

            // A redelivery queues nothing more and keeps the same chat
            $redelivered = $this->sendCommentEvent('issue_comment', $pullRequest, $question, '@' . $handle . ' what does this change?');
            $this->assertSame(200, $redelivered['headers']['status-code']);
            $this->assertSame(204, $this->createChatUsage($repositoryId, $pullRequest, $question)['headers']['status-code']);
        }

        /**
         * Test for FAILURE
         */

        // A conversation comment without a mention is not for the reviewer
        $plain = $reply + 2;
        $this->sendCommentEvent('issue_comment', $pullRequest, $plain, 'Looks good to me.');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $plain);

        // Edits, other apps and the first comment of a thread without a mention queue nothing
        $edited = $reply + 3;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $edited, 'Why?', replyTo: $reply - 1, action: 'edited');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $edited);

        $bot = $reply + 4;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $bot, 'Why?', replyTo: $reply - 1, authorType: 'Bot');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $bot);

        $threadStart = $reply + 5;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $threadStart, 'Why?');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $threadStart);

        // Chat usage is keyed on the pull request and the repository the chat was queued for
        $this->assertChatNotQueued($repositoryId, $pullRequest + 1, $reply);

        $usage = $this->createChatUsage($repositoryId, $pullRequest, $reply, [['role' => 'chat']]);
        $this->assertSame(400, $usage['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repositoryId, $this->projectHeaders());
    }

    public function testServiceNeedsTheSecret(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for FAILURE
         */
        foreach (['', 'wrong-secret'] as $secret) {
            $response = $this->client->call(Client::METHOD_PATCH, '/reviews/unknown/status', [
                'content-type' => 'application/json',
                'x-appwrite-reviews-secret' => $secret,
            ], [
                'projectId' => $projectId,
                'status' => 'failed',
                'reason' => 'Nope.',
            ]);

            $this->assertSame(401, $response['headers']['status-code']);
            $this->assertSame('user_unauthorized', $response['body']['type']);
        }

        $response = $this->callService(Client::METHOD_PATCH, '/reviews/unknown/status', [
            'projectId' => $projectId,
            'status' => 'failed',
            'reason' => 'Nope.',
        ]);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('review_not_found', $response['body']['type']);
    }

    public function testRepositoryTokenNeedsCodeReview(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for FAILURE
         */
        $response = $this->callService(Client::METHOD_POST, '/reviews/repositories/unknown/tokens', [
            'projectId' => $projectId,
        ]);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame('repository_not_found', $response['body']['type']);
    }

    public function testRepositoryToken(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();

        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $token = $this->callService(Client::METHOD_POST, '/reviews/repositories/' . $repository['body']['$id'] . '/tokens', [
            'projectId' => $projectId,
        ]);
        $this->assertSame(201, $token['headers']['status-code']);
        $this->assertNotEmpty($token['body']['token']);
        $this->assertGreaterThan(\time(), \strtotime($token['body']['expiresAt']));

        /**
         * Test for FAILURE
         */

        // Another project cannot get a token for it
        $response = $this->callService(Client::METHOD_POST, '/reviews/repositories/' . $repository['body']['$id'] . '/tokens', [
            'projectId' => 'another-project',
        ]);
        $this->assertSame(404, $response['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repository['body']['$id'], $this->projectHeaders());
    }

    private function projectHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * Calls the internal API the way the review service does: no project header, the shared secret.
     */
    private function callService(string $method, string $path, array $body): array
    {
        return $this->client->call($method, $path, [
            'content-type' => 'application/json',
            'x-appwrite-reviews-secret' => System::getEnv('_APP_REVIEWS_SECRET', ''),
        ], $body);
    }

    private function listReviews(int $pullRequest, array $queries = []): array
    {
        $reviews = $this->client->call(Client::METHOD_GET, '/reviews', $this->projectHeaders(), [
            'queries' => [
                Query::equal('providerPullRequestId', [(string) $pullRequest])->toString(),
                ...$queries,
            ],
        ]);
        $this->assertSame(200, $reviews['headers']['status-code']);

        return $reviews;
    }

    private function createChatUsage(string $repositoryId, int $pullRequest, int $commentId, ?array $usage = null): array
    {
        return $this->callService(Client::METHOD_POST, '/reviews/repositories/' . $repositoryId . '/usage', [
            'projectId' => $this->getProject()['$id'],
            'pullNumber' => $pullRequest,
            'commentId' => $commentId,
            'usage' => $usage ?? [
                ['role' => 'chat', 'provider' => 'anthropic', 'model' => 'claude', 'inputTokens' => 800, 'outputTokens' => 120, 'durationMs' => 2100],
            ],
        ]);
    }

    private function assertChatNotQueued(string $repositoryId, int $pullRequest, int $commentId): void
    {
        $usage = $this->createChatUsage($repositoryId, $pullRequest, $commentId);
        $this->assertSame(404, $usage['headers']['status-code']);
        $this->assertSame('review_chat_not_found', $usage['body']['type']);
    }

    private function sendCommentEvent(string $event, int $pullRequest, int $commentId, string $body, ?int $replyTo = null, string $action = 'created', string $authorType = 'User'): array
    {
        $comment = [
            'id' => $commentId,
            'body' => $body,
            'html_url' => 'https://github.com/appwrite-test/svelte-starter/pull/' . $pullRequest . '#comment-' . $commentId,
            'author_association' => 'MEMBER',
            'user' => ['login' => 'appwrite-test', 'type' => $authorType],
        ];
        if ($replyTo !== null) {
            $comment['in_reply_to_id'] = $replyTo;
        }

        $payload = [
            'action' => $action,
            'comment' => $comment,
            'repository' => [
                'id' => (int) $this->providerRepositoryId,
                'name' => 'svelte-starter',
                'owner' => ['login' => 'appwrite-test'],
            ],
            'installation' => ['id' => (int) $this->providerInstallationId],
        ];
        if ($event === 'issue_comment') {
            $payload['issue'] = ['number' => $pullRequest, 'pull_request' => ['url' => 'https://api.github.com/repos/appwrite-test/svelte-starter/pulls/' . $pullRequest]];
        } else {
            $payload['pull_request'] = ['number' => $pullRequest];
        }

        return $this->client->call(Client::METHOD_POST, '/vcs/github/events', [
            'content-type' => 'application/json',
            'x-github-event' => $event,
            'x-hub-signature-256' => 'sha256=' . \hash_hmac(
                'sha256',
                \json_encode($payload, JSON_THROW_ON_ERROR),
                System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', ''),
            ),
        ], $payload);
    }

    private function sendPullRequestEvent(string $action, int $pullRequest, string $head): array
    {
        $payload = [
            'action' => $action,
            'number' => $pullRequest,
            'pull_request' => [
                'head' => ['ref' => 'review-e2e', 'sha' => $head, 'user' => ['login' => 'appwrite-test']],
                'base' => ['ref' => 'main', 'user' => ['login' => 'appwrite-test']],
                'user' => ['avatar_url' => ''],
            ],
            'repository' => [
                'id' => (int) $this->providerRepositoryId,
                'name' => 'svelte-starter',
                'html_url' => 'https://github.com/appwrite-test/svelte-starter',
                'owner' => ['login' => 'appwrite-test'],
            ],
            'installation' => ['id' => (int) $this->providerInstallationId],
            'sender' => ['html_url' => 'https://github.com/appwrite-test'],
        ];

        // GitHub webhooks are public and carry no x-appwrite-project header.
        return $this->client->call(Client::METHOD_POST, '/vcs/github/events', [
            'content-type' => 'application/json',
            'x-github-event' => 'pull_request',
            'x-hub-signature-256' => 'sha256=' . \hash_hmac(
                'sha256',
                \json_encode($payload, JSON_THROW_ON_ERROR),
                System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', ''),
            ),
        ], $payload);
    }
}
