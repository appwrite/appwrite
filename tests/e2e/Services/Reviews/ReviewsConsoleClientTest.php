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
        $firstReviewId = $review['$id'];

        // A push while it is still queued supersedes it with a review of the new head
        $this->sendPullRequestEvent('synchronize', $pullRequest, $secondHead);

        $queued = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $queued['body']['total']);
        $this->assertSame($secondHead, $queued['body']['reviews'][0]['providerCommitHash']);
        $this->assertSame('synchronize', $queued['body']['reviews'][0]['trigger']);
        $reviewId = $queued['body']['reviews'][0]['$id'];
        $this->assertNotSame($firstReviewId, $reviewId);

        $superseded = $this->client->call(Client::METHOD_GET, '/reviews/' . $firstReviewId, $this->projectHeaders());
        $this->assertSame('superseded', $superseded['body']['status']);

        // The service reads the pull request's reviews back, newest first
        $pullReviews = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/pulls/' . $pullRequest . '/reviews', [
            'projectId' => $projectId,
        ]);
        $this->assertSame(200, $pullReviews['headers']['status-code']);
        $this->assertSame([$reviewId, $firstReviewId], \array_column($pullReviews['body']['reviews'], 'id'));
        $this->assertSame('superseded', $pullReviews['body']['reviews'][1]['status']);

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

        // A push while it runs queues a second review and leaves the running one alone
        $this->sendPullRequestEvent('synchronize', $pullRequest, \str_repeat('d', 40));
        $queued = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $queued['body']['total']);
        $this->assertNotSame($reviewId, $queued['body']['reviews'][0]['$id']);
        $review = $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders());
        $this->assertSame('processing', $review['body']['status']);

        $completed = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'completed',
            'verdict' => 'comment',
            'tier' => 'B',
            'tierReason' => 'One major finding is open.',
            'summary' => 'One missing null check.',
            'walkthrough' => [['path' => 'src/index.ts', 'change' => 'Reads the user.']],
            'providerReviewId' => 1834620151,
            'findings' => [
                [
                    'path' => 'src/index.ts',
                    'startLine' => 12,
                    'endLine' => 14,
                    'severity' => 'major',
                    'category' => 'bug',
                    'title' => 'Missing null check',
                    'body' => '`user` can be null here.',
                    'suggestion' => 'if (!user) return;',
                    'status' => 'posted',
                    'dropReason' => null,
                    'providerCommentId' => 2468013579,
                ],
                [
                    'path' => 'src/index.ts',
                    'startLine' => 20,
                    'endLine' => 20,
                    'severity' => 'minor',
                    'category' => 'style',
                    'title' => 'Unused import',
                    'body' => '`fs` is not used.',
                    'suggestion' => null,
                    'status' => 'dropped',
                    'dropReason' => 'Not confirmed by verification.',
                    'providerCommentId' => null,
                ],
            ],
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
        $this->assertSame('One major finding is open.', $review['body']['tierReason']);
        $this->assertSame([['path' => 'src/index.ts', 'change' => 'Reads the user.']], $review['body']['walkthrough']);
        $this->assertCount(2, $review['body']['findings']);
        $this->assertSame('src/index.ts', $review['body']['findings'][0]['path']);
        $this->assertSame(12, $review['body']['findings'][0]['startLine']);

        // The service reads the findings back, settles one and regrades the review
        $findings = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/pulls/' . $pullRequest . '/findings', [
            'projectId' => $projectId,
        ]);
        $this->assertSame(200, $findings['headers']['status-code']);
        $this->assertCount(2, $findings['body']['findings']);
        $posted = $findings['body']['findings'][0];
        $this->assertSame('Missing null check', $posted['title']);
        $this->assertSame('posted', $posted['status']);
        $this->assertSame(2468013579, $posted['providerCommentId']);
        $this->assertSame('if (!user) return;', $posted['suggestion']);
        $this->assertSame($reviewId, $posted['reviewId']);
        $this->assertSame('dropped', $findings['body']['findings'][1]['status']);
        $this->assertNull($findings['body']['findings'][1]['providerCommentId']);
        $this->assertNull($findings['body']['findings'][1]['suggestion']);

        $settled = $this->callService(Client::METHOD_PATCH, '/reviews/repositories/' . $repositoryId . '/pulls/' . $pullRequest . '/findings/status', [
            'projectId' => $projectId,
            'findingIds' => [$posted['id'], $findings['body']['findings'][1]['id']],
            'status' => 'dismissed',
            'reason' => 'Thread resolved on GitHub by @appwrite-test',
            'from' => 'posted',
        ]);
        $this->assertSame(204, $settled['headers']['status-code']);

        $regraded = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/tier', [
            'projectId' => $projectId,
            'tier' => 'S',
            'tierReason' => 'Nothing is open.',
        ]);
        $this->assertSame(204, $regraded['headers']['status-code']);

        $findings = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/pulls/' . $pullRequest . '/findings', [
            'projectId' => $projectId,
            'limit' => 1,
        ]);
        $this->assertCount(1, $findings['body']['findings']);
        $this->assertSame('dismissed', $findings['body']['findings'][0]['status']);
        $this->assertSame('Thread resolved on GitHub by @appwrite-test', $findings['body']['findings'][0]['dropReason']);

        $review = $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders());
        $this->assertSame('S', $review['body']['tier']);
        $this->assertSame('Nothing is open.', $review['body']['tierReason']);
        // Only findings still posted moved: the dropped one keeps its status
        $statuses = \array_column($review['body']['findings'], 'status', 'title');
        $this->assertSame('dropped', $statuses['Unused import']);

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
        $this->assertCount(2, $review['body']['findings']);

        // A superseded review never starts
        $started = $this->callService(Client::METHOD_PATCH, '/reviews/' . $firstReviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'processing',
            'headSha' => $firstHead,
            'baseSha' => \str_repeat('c', 40),
        ]);
        $this->assertSame(409, $started['headers']['status-code']);

        // Only a completed review has a tier to change
        $regraded = $this->callService(Client::METHOD_PATCH, '/reviews/' . $firstReviewId . '/tier', [
            'projectId' => $projectId,
            'tier' => 'S',
        ]);
        $this->assertSame(409, $regraded['headers']['status-code']);
        $this->assertSame('review_status_invalid', $regraded['body']['type']);

        // Another project cannot read the pull request's reviews
        $pullReviews = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/pulls/' . $pullRequest . '/reviews', [
            'projectId' => 'another-project',
        ]);
        $this->assertSame(404, $pullReviews['headers']['status-code']);

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

        // Another app may ask, e.g. an agent answering a finding
        $agent = $reply + 6;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $agent, 'Fixed in the latest commit.', replyTo: $reply - 1, authorType: 'Bot', author: 'cursor[bot]', association: 'NONE');
        $this->assertSame(204, $this->createChatUsage($repositoryId, $pullRequest, $agent)['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        // A conversation comment without a mention is not for the reviewer while no finding is listed in a review body
        $plain = $reply + 2;
        $this->sendCommentEvent('issue_comment', $pullRequest, $plain, 'Looks good to me.');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $plain);

        // Edits, the reviewer itself, people without write association and the first comment
        // of a thread without a mention queue nothing
        $edited = $reply + 3;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $edited, 'Why?', replyTo: $reply - 1, action: 'edited');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $edited);

        if ($handle !== '') {
            $itself = $reply + 4;
            $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $itself, 'Why?', replyTo: $reply - 1, authorType: 'Bot', author: $handle . '[bot]');
            $this->assertChatNotQueued($repositoryId, $pullRequest, $itself);

            $quoted = $reply + 7;
            $this->sendCommentEvent('issue_comment', $pullRequest, $quoted, "> @{$handle} what does this change?\n\nSee `@{$handle} review`.");
            $this->assertChatNotQueued($repositoryId, $pullRequest, $quoted);
        }

        $outsider = $reply + 8;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $outsider, 'Why?', replyTo: $reply - 1, association: 'CONTRIBUTOR');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $outsider);

        $threadStart = $reply + 5;
        $this->sendCommentEvent('pull_request_review_comment', $pullRequest, $threadStart, 'Why?');
        $this->assertChatNotQueued($repositoryId, $pullRequest, $threadStart);

        // Chat usage is keyed on the pull request and the repository the chat was queued for
        $this->assertChatNotQueued($repositoryId, $pullRequest + 1, $reply);

        $usage = $this->createChatUsage($repositoryId, $pullRequest, $reply, [['role' => 'chat']]);
        $this->assertSame(400, $usage['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repositoryId, $this->projectHeaders());
    }

    public function testMentionReviewAndBodyFindings(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();
        $handle = System::getEnv('_APP_VCS_GITHUB_APP_NAME', '');
        if ($handle === '') {
            $this->markTestSkipped('No GitHub App name to mention.');
        }
        $pullRequest = \random_int(1000, 9_999_999);
        $comment = \random_int(1_000_000, 9_999_999_999);

        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);
        $repositoryId = $repository['body']['$id'];

        /**
         * Test for SUCCESS
         */

        // "@app full review" queues a review of whatever the head is when it runs, superseding a queued one
        $this->sendPullRequestEvent('opened', $pullRequest, \str_repeat('a', 40));
        $this->sendCommentEvent('issue_comment', $pullRequest, $comment, '@' . \strtoupper($handle) . ' full review, please');

        $mention = $this->client->call(Client::METHOD_GET, '/reviews/mention-' . $comment, $this->projectHeaders());
        $this->assertSame(200, $mention['headers']['status-code']);
        $this->assertSame('queued', $mention['body']['status']);
        $this->assertSame('mention', $mention['body']['trigger']);
        $this->assertSame('', $mention['body']['providerCommitHash']);
        $this->assertSame((string) $comment, $mention['body']['providerCommentId']);

        $queued = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $queued['body']['total']);
        $this->assertSame('mention-' . $comment, $queued['body']['reviews'][0]['$id']);

        // It completes with a finding GitHub took only in the review body
        $reviewId = 'mention-' . $comment;
        $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'processing',
            'headSha' => \str_repeat('b', 40),
            'baseSha' => \str_repeat('c', 40),
        ]);
        $completed = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'completed',
            'verdict' => 'comment',
            'tier' => 'A',
            'findings' => [[
                'path' => 'assets/logo.png',
                'startLine' => 1,
                'endLine' => 1,
                'severity' => 'minor',
                'category' => 'docs',
                'title' => 'Logo is not optimized',
                'body' => 'It is 2 MB.',
                'status' => 'posted',
                'providerCommentId' => null,
            ]],
        ]);
        $this->assertSame(204, $completed['headers']['status-code']);

        // Now a conversation comment without a mention may be answering that finding
        $answer = $comment + 1;
        $this->sendCommentEvent('issue_comment', $pullRequest, $answer, 'The logo is fine as it is.');
        $this->assertSame(204, $this->createChatUsage($repositoryId, $pullRequest, $answer)['headers']['status-code']);

        // A learning from the conversation is kept for the repository's later reviews
        $learning = $this->callService(Client::METHOD_POST, '/reviews/repositories/' . $repositoryId . '/learnings', [
            'projectId' => $projectId,
            'body' => 'Do not flag image sizes in assets/.',
            'author' => 'appwrite-test',
            'sourceUrl' => 'https://github.com/appwrite-test/svelte-starter/pull/' . $pullRequest . '#issuecomment-' . $answer,
        ]);
        $this->assertSame(204, $learning['headers']['status-code']);

        $learnings = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/learnings', [
            'projectId' => $projectId,
        ]);
        $this->assertSame(200, $learnings['headers']['status-code']);
        $this->assertSame(['Do not flag image sizes in assets/.'], $learnings['body']['learnings']);

        /**
         * Test for FAILURE
         */

        // A redelivered mention queues nothing more
        $this->sendCommentEvent('issue_comment', $pullRequest, $comment, '@' . $handle . ' full review, please');
        $this->assertSame('completed', $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders())['body']['status']);

        // Another project cannot read the repository's learnings
        $learnings = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/learnings', [
            'projectId' => 'another-project',
        ]);
        $this->assertSame(404, $learnings['headers']['status-code']);

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
        $this->assertSame(System::getEnv('_APP_VCS_GITHUB_APP_ID', ''), $token['body']['appId']);
        $this->assertSame(System::getEnv('_APP_VCS_GITHUB_APP_NAME', ''), $token['body']['appSlug']);
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

    private function sendCommentEvent(string $event, int $pullRequest, int $commentId, string $body, ?int $replyTo = null, string $action = 'created', string $authorType = 'User', string $author = 'appwrite-test', string $association = 'MEMBER'): array
    {
        $comment = [
            'id' => $commentId,
            'body' => $body,
            'html_url' => 'https://github.com/appwrite-test/svelte-starter/pull/' . $pullRequest . '#comment-' . $commentId,
            'author_association' => $association,
            'user' => ['login' => $author, 'type' => $authorType],
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
