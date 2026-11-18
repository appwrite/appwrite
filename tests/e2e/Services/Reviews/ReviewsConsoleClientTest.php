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

    protected function setupInstallation(?string $projectId = null): string
    {
        // Same gate as the VCSGitHub suite: without the GitHub App there is no installation to use.
        if (System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled') {
            $this->markTestSkipped('VCS is not enabled.');
        }

        $projectId ??= $this->getProject()['$id'];

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
        $this->assertSame('normal', $repository['body']['strength']);
        $repositoryId = $repository['body']['$id'];

        // Review it at expert
        $strength = $this->client->call(Client::METHOD_PATCH, '/reviews/repositories/' . $repositoryId . '/strength', $this->projectHeaders(), [
            'strength' => 'expert',
        ]);
        $this->assertSame(200, $strength['headers']['status-code']);
        $this->assertSame('expert', $strength['body']['strength']);

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
        $this->assertSame('expert', $review['strength']);
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
                // A step with no data arrives as an empty JSON object
                ['type' => 'tool.list_files', 'at' => '2026-10-27T15:00:01.000Z', 'data' => new \stdClass()],
            ],
        ]);
        $this->assertSame(204, $events['headers']['status-code']);

        // The Console reads the trace back, oldest step first
        $trace = $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId . '/events', $this->projectHeaders());
        $this->assertSame(200, $trace['headers']['status-code']);
        $this->assertSame(2, $trace['body']['total']);
        $this->assertSame('tool_call', $trace['body']['events'][0]['type']);
        $this->assertSame(['tool' => 'read_file'], $trace['body']['events'][0]['data']);
        $this->assertSame('tool.list_files', $trace['body']['events'][1]['type']);
        $this->assertSame([], $trace['body']['events'][1]['data']);

        $missing = $this->client->call(Client::METHOD_GET, '/reviews/missing/events', $this->projectHeaders());
        $this->assertSame(404, $missing['headers']['status-code']);

        $usage = $this->callService(Client::METHOD_POST, '/reviews/' . $reviewId . '/usage', [
            'projectId' => $projectId,
            'usage' => [
                ['role' => 'review', 'provider' => 'anthropic', 'model' => 'claude', 'inputTokens' => 1200, 'outputTokens' => 300, 'durationMs' => 4100],
            ],
        ]);
        $this->assertSame(204, $usage['headers']['status-code']);

        // A push while it runs queues a second review and leaves the running one alone. The new
        // review takes the strength the repository has now; the running one keeps its own.
        $strength = $this->client->call(Client::METHOD_PATCH, '/reviews/repositories/' . $repositoryId . '/strength', $this->projectHeaders(), [
            'strength' => 'basic',
        ]);
        $this->assertSame(200, $strength['headers']['status-code']);
        $this->sendPullRequestEvent('synchronize', $pullRequest, \str_repeat('d', 40));
        $queued = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $queued['body']['total']);
        $this->assertNotSame($reviewId, $queued['body']['reviews'][0]['$id']);
        $this->assertSame('basic', $queued['body']['reviews'][0]['strength']);
        $review = $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders());
        $this->assertSame('processing', $review['body']['status']);
        $this->assertSame('expert', $review['body']['strength']);

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

        // The completed review and its tokens are metered for the project, on its repository
        $usageHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getNewKey(['usage.read']),
        ];
        $this->assertEventually(function () use ($usageHeaders, $repositoryId) {
            $response = $this->client->call(Client::METHOD_GET, '/usage/events', $usageHeaders, [
                'metrics' => ['reviews', 'reviews.expert', 'reviews.tokens.input', 'reviews.tokens.output', 'reviews.tokens.claude.input'],
                'queries' => [Query::equal('resourceId', [$repositoryId])->toString()],
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $totals = [];
            foreach ($response['body']['metrics'] as $series) {
                $totals[$series['metric']] = \array_sum(\array_column($series['points'], 'value'));
            }
            $this->assertEquals(1, $totals['reviews']);
            // Billed at the strength it was queued at, not the repository's current one
            $this->assertEquals(1, $totals['reviews.expert']);
            $this->assertEquals(1200, $totals['reviews.tokens.input']);
            $this->assertEquals(300, $totals['reviews.tokens.output']);
            $this->assertEquals(1200, $totals['reviews.tokens.claude.input']);
        }, 60_000, 500);

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

        // A strength the service does not know, and a repository without code review
        $strength = $this->client->call(Client::METHOD_PATCH, '/reviews/repositories/' . $repositoryId . '/strength', $this->projectHeaders(), [
            'strength' => 'max',
        ]);
        $this->assertSame(400, $strength['headers']['status-code']);

        $strength = $this->client->call(Client::METHOD_PATCH, '/reviews/repositories/missing/strength', $this->projectHeaders(), [
            'strength' => 'expert',
        ]);
        $this->assertSame(404, $strength['headers']['status-code']);

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

    public function testSeveralProjectsReviewOneRepository(): void
    {
        $installationId = $this->setupInstallation();
        $otherProjectId = $this->getProject(true)['$id'];
        $otherInstallationId = $this->setupInstallation($otherProjectId);
        $pullRequest = \random_int(1000, 9_999_999);
        $head = \str_repeat('d', 40);

        /**
         * Test for SUCCESS
         */

        // Both projects turn code review on for the same repository
        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);

        $otherRepository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders($otherProjectId), [
            'installationId' => $otherInstallationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $otherRepository['headers']['status-code']);
        $this->assertNotSame($repository['body']['$id'], $otherRepository['body']['$id']);

        // Each project lists only its own
        $repositories = $this->client->call(Client::METHOD_GET, '/reviews/repositories', $this->projectHeaders($otherProjectId));
        $this->assertSame(1, $repositories['body']['total']);
        $this->assertSame($otherRepository['body']['$id'], $repositories['body']['repositories'][0]['$id']);

        // One pull request queues a review in each project
        $this->sendPullRequestEvent('opened', $pullRequest, $head);

        $reviews = $this->listReviews($pullRequest);
        $this->assertSame(1, $reviews['body']['total']);
        $this->assertSame($repository['body']['$id'], $reviews['body']['reviews'][0]['repositoryId']);

        $otherReviews = $this->listReviews($pullRequest, [], $otherProjectId);
        $this->assertSame(1, $otherReviews['body']['total']);
        $this->assertSame($otherRepository['body']['$id'], $otherReviews['body']['reviews'][0]['repositoryId']);

        // Turning it off in one project leaves the other reviewing
        $deleted = $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $otherRepository['body']['$id'], $this->projectHeaders($otherProjectId));
        $this->assertSame(204, $deleted['headers']['status-code']);

        $nextPullRequest = $pullRequest + 1;
        $this->sendPullRequestEvent('opened', $nextPullRequest, $head);
        $this->assertSame(1, $this->listReviews($nextPullRequest)['body']['total']);
        $this->assertSame(0, $this->listReviews($nextPullRequest, [], $otherProjectId)['body']['total']);

        /**
         * Test for FAILURE
         */

        // A project still turns code review on for a repository only once
        $duplicate = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('review_repository_already_exists', $duplicate['body']['type']);

        // A project cannot read the other project's repository
        $other = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repository['body']['$id'] . '/pulls/' . $pullRequest . '/reviews', [
            'projectId' => $otherProjectId,
        ]);
        $this->assertSame(404, $other['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repository['body']['$id'], $this->projectHeaders());
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

        // Each answered comment is counted once, however many usage reports it sends
        $usageHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['usage.read']),
        ];
        $answered = $handle !== '' ? 3 : 2;
        $this->assertEventually(function () use ($usageHeaders, $repositoryId, $answered) {
            $response = $this->client->call(Client::METHOD_GET, '/usage/events', $usageHeaders, [
                'metrics' => ['reviews.chats'],
                'queries' => [Query::equal('resourceId', [$repositoryId])->toString()],
            ]);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertEquals($answered, \array_sum(\array_column($response['body']['metrics'][0]['points'], 'value')));
        }, 60_000, 500);

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

        // A redelivered mention queues nothing more, and leaves a review queued after it alone
        $this->sendPullRequestEvent('synchronize', $pullRequest, \str_repeat('d', 40));
        $newer = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $newer['body']['total']);

        $this->sendCommentEvent('issue_comment', $pullRequest, $comment, '@' . $handle . ' full review, please');
        $this->assertSame('completed', $this->client->call(Client::METHOD_GET, '/reviews/' . $reviewId, $this->projectHeaders())['body']['status']);
        $queued = $this->listReviews($pullRequest, [Query::equal('status', ['queued'])->toString()]);
        $this->assertSame(1, $queued['body']['total']);
        $this->assertSame($newer['body']['reviews'][0]['$id'], $queued['body']['reviews'][0]['$id']);

        // Another project cannot read the repository's learnings
        $learnings = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/learnings', [
            'projectId' => 'another-project',
        ]);
        $this->assertSame(404, $learnings['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repositoryId, $this->projectHeaders());
    }

    public function testReviewNewRepositories(): void
    {
        $installationId = $this->setupInstallation();
        $otherProjectId = $this->getProject(true)['$id'];
        $otherInstallationId = $this->setupInstallation($otherProjectId);
        // Repositories added to the installation are not looked up on GitHub, so any id will do.
        $added = (string) \random_int(1_000_000_000, 9_999_999_999);
        $addedLater = (string) \random_int(1_000_000_000, 9_999_999_999);

        /**
         * Test for SUCCESS
         */

        // Off until the project asks for it
        $installation = $this->client->call(Client::METHOD_GET, '/vcs/installations/' . $installationId, $this->projectHeaders());
        $this->assertSame(200, $installation['headers']['status-code']);
        $this->assertFalse($installation['body']['reviewNewRepositories']);

        $installation = $this->client->call(Client::METHOD_PATCH, '/reviews/installations/' . $installationId, $this->projectHeaders(), [
            'newRepositories' => true,
        ]);
        $this->assertSame(200, $installation['headers']['status-code']);
        $this->assertSame($installationId, $installation['body']['$id']);
        $this->assertTrue($installation['body']['reviewNewRepositories']);

        $installation = $this->client->call(Client::METHOD_GET, '/vcs/installations/' . $installationId, $this->projectHeaders());
        $this->assertTrue($installation['body']['reviewNewRepositories']);

        // A repository added to the installation is reviewed at normal strength
        $event = $this->sendInstallationRepositoriesEvent('added', [$added]);
        $this->assertSame(200, $event['headers']['status-code']);

        $repositories = $this->listRepositories([$added]);
        $this->assertSame(1, $repositories['body']['total']);
        $repository = $repositories['body']['repositories'][0];
        $this->assertSame($installationId, $repository['installationId']);
        $this->assertSame('normal', $repository['strength']);

        // Only in the project that asked for it
        $this->assertSame(0, $this->listRepositories([$added], $otherProjectId)['body']['total']);

        // A second delivery, or a repository already reviewed, keeps the row it has
        $this->client->call(Client::METHOD_PATCH, '/reviews/repositories/' . $repository['$id'] . '/strength', $this->projectHeaders(), [
            'strength' => 'expert',
        ]);
        $this->sendInstallationRepositoriesEvent('added', [$added]);
        $repositories = $this->listRepositories([$added]);
        $this->assertSame(1, $repositories['body']['total']);
        $this->assertSame('expert', $repositories['body']['repositories'][0]['strength']);

        // Removing a repository from the installation does not add it
        $this->sendInstallationRepositoriesEvent('removed', [$addedLater]);
        $this->assertSame(0, $this->listRepositories([$addedLater])['body']['total']);

        // Turned off, new repositories are left alone
        $installation = $this->client->call(Client::METHOD_PATCH, '/reviews/installations/' . $installationId, $this->projectHeaders(), [
            'newRepositories' => false,
        ]);
        $this->assertSame(200, $installation['headers']['status-code']);
        $this->assertFalse($installation['body']['reviewNewRepositories']);

        $this->sendInstallationRepositoriesEvent('added', [$addedLater]);
        $this->assertSame(0, $this->listRepositories([$addedLater])['body']['total']);

        /**
         * Test for FAILURE
         */

        // Another project's installation is not found
        $other = $this->client->call(Client::METHOD_PATCH, '/reviews/installations/' . $otherInstallationId, $this->projectHeaders(), [
            'newRepositories' => true,
        ]);
        $this->assertSame(404, $other['headers']['status-code']);
        $this->assertSame('installation_not_found', $other['body']['type']);

        $unknown = $this->client->call(Client::METHOD_PATCH, '/reviews/installations/unknown', $this->projectHeaders(), [
            'newRepositories' => true,
        ]);
        $this->assertSame(404, $unknown['headers']['status-code']);

        $invalid = $this->client->call(Client::METHOD_PATCH, '/reviews/installations/' . $installationId, $this->projectHeaders(), [
            'newRepositories' => 'maybe',
        ]);
        $this->assertSame(400, $invalid['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repository['$id'], $this->projectHeaders());
    }

    public function testLearnings(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();

        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);
        $repositoryId = $repository['body']['$id'];

        foreach (['Do not flag TODO comments.', 'Prefer early returns.'] as $body) {
            $learning = $this->callService(Client::METHOD_POST, '/reviews/repositories/' . $repositoryId . '/learnings', [
                'projectId' => $projectId,
                'body' => $body,
                'author' => 'appwrite-test',
                'sourceUrl' => 'https://github.com/appwrite-test/svelte-starter/pull/1#issuecomment-1',
            ]);
            $this->assertSame(204, $learning['headers']['status-code']);
        }

        /**
         * Test for SUCCESS
         */

        // The project lists what its reviews were asked to remember
        $learnings = $this->client->call(Client::METHOD_GET, '/reviews/learnings', $this->projectHeaders(), [
            'queries' => [
                Query::equal('repositoryId', [$repositoryId])->toString(),
                Query::orderAsc('$createdAt')->toString(),
            ],
        ]);
        $this->assertSame(200, $learnings['headers']['status-code']);
        $this->assertSame(2, $learnings['body']['total']);
        $first = $learnings['body']['learnings'][0];
        $this->assertSame('Do not flag TODO comments.', $first['body']);
        $this->assertSame($repositoryId, $first['repositoryId']);
        $this->assertSame('appwrite-test', $first['author']);
        $this->assertSame('https://github.com/appwrite-test/svelte-starter/pull/1#issuecomment-1', $first['sourceUrl']);
        $this->assertNotEmpty($first['$createdAt']);

        // A deleted learning is no longer given to the review service
        $deleted = $this->client->call(Client::METHOD_DELETE, '/reviews/learnings/' . $first['$id'], $this->projectHeaders());
        $this->assertSame(204, $deleted['headers']['status-code']);

        $learnings = $this->client->call(Client::METHOD_GET, '/reviews/learnings', $this->projectHeaders(), [
            'queries' => [Query::equal('repositoryId', [$repositoryId])->toString()],
        ]);
        $this->assertSame(1, $learnings['body']['total']);
        $this->assertSame('Prefer early returns.', $learnings['body']['learnings'][0]['body']);

        $service = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/learnings', [
            'projectId' => $projectId,
        ]);
        $this->assertSame(['Prefer early returns.'], $service['body']['learnings']);

        /**
         * Test for FAILURE
         */

        $deleted = $this->client->call(Client::METHOD_DELETE, '/reviews/learnings/' . $first['$id'], $this->projectHeaders());
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('review_learning_not_found', $deleted['body']['type']);

        $invalid = $this->client->call(Client::METHOD_GET, '/reviews/learnings', $this->projectHeaders(), [
            'queries' => [Query::equal('body', ['Prefer early returns.'])->toString()],
        ]);
        $this->assertSame(400, $invalid['headers']['status-code']);

        // Another project sees none of them
        $learnings = $this->client->call(Client::METHOD_GET, '/reviews/learnings', $this->projectHeaders($this->getProject(true)['$id']), [
            'queries' => [Query::equal('repositoryId', [$repositoryId])->toString()],
        ]);
        $this->assertSame(200, $learnings['headers']['status-code']);
        $this->assertSame(0, $learnings['body']['total']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repositoryId, $this->projectHeaders());
    }

    public function testAuthors(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();

        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);
        $repositoryId = $repository['body']['$id'];

        $alicePull = \random_int(1000, 9_999_999);
        $bobPull = $alicePull + 1;

        // Alice's first review asks for changes; the second, after a push, approves
        $this->sendPullRequestEvent('opened', $alicePull, \str_repeat('a', 40), 'alice');
        $first = $this->listReviews($alicePull)['body']['reviews'][0];
        $this->assertSame('alice', $first['providerPullRequestAuthor']);
        $this->completeReview($projectId, $first['$id'], 'request_changes', 'C', [
            ['severity' => 'critical', 'status' => 'posted'],
            ['severity' => 'major', 'status' => 'posted'],
            ['severity' => 'minor', 'status' => 'dropped'],
        ]);

        $findings = $this->callService(Client::METHOD_GET, '/reviews/repositories/' . $repositoryId . '/pulls/' . $alicePull . '/findings', [
            'projectId' => $projectId,
        ]);
        $critical = \array_values(\array_filter($findings['body']['findings'], fn (array $finding) => $finding['severity'] === 'critical'))[0];
        $resolved = $this->callService(Client::METHOD_PATCH, '/reviews/repositories/' . $repositoryId . '/pulls/' . $alicePull . '/findings/status', [
            'projectId' => $projectId,
            'findingIds' => [$critical['id']],
            'status' => 'resolved',
            'from' => 'posted',
        ]);
        $this->assertSame(204, $resolved['headers']['status-code']);

        $this->sendPullRequestEvent('synchronize', $alicePull, \str_repeat('b', 40), 'alice');
        $second = $this->listReviews($alicePull, [Query::equal('status', ['queued'])->toString()])['body']['reviews'][0];
        $this->completeReview($projectId, $second['$id'], 'approve', 'A');

        // Bob's pull request is approved by its first review
        $this->sendPullRequestEvent('opened', $bobPull, \str_repeat('c', 40), 'bob');
        $this->completeReview($projectId, $this->listReviews($bobPull)['body']['reviews'][0]['$id'], 'approve', 'S');

        // A review still running counts for nobody
        $this->sendPullRequestEvent('opened', $bobPull + 1, \str_repeat('d', 40), 'carol');

        /**
         * Test for SUCCESS
         */

        $authors = $this->client->call(Client::METHOD_GET, '/reviews/authors', $this->projectHeaders(), [
            'repositoryId' => $repositoryId,
        ]);
        $this->assertSame(200, $authors['headers']['status-code']);
        $this->assertSame(2, $authors['body']['total']);
        $this->assertSame(['alice', 'bob'], \array_column($authors['body']['authors'], 'author'));

        $alice = $authors['body']['authors'][0];
        $this->assertSame(1, $alice['pulls']);
        $this->assertSame(2, $alice['reviews']);
        $this->assertSame(1, $alice['approved']);
        $this->assertSame(0, $alice['approvedFirst']);
        // The dropped finding was never posted
        $this->assertSame(2, $alice['findings']);
        $this->assertSame(1, $alice['critical']);
        $this->assertSame(1, $alice['major']);
        $this->assertSame(1, $alice['resolved']);
        $this->assertSame(0, $alice['dismissed']);
        // The latest review's tier, not the first one's
        $this->assertSame('A', $alice['tier']);
        $this->assertNotEmpty($alice['lastReviewAt']);

        $bob = $authors['body']['authors'][1];
        $this->assertSame(1, $bob['pulls']);
        $this->assertSame(1, $bob['approvedFirst']);
        $this->assertSame(0, $bob['findings']);
        $this->assertSame('S', $bob['tier']);

        $week = $this->client->call(Client::METHOD_GET, '/reviews/authors', $this->projectHeaders(), [
            'repositoryId' => $repositoryId,
            'range' => '7d',
        ]);
        $this->assertSame(200, $week['headers']['status-code']);
        $this->assertSame(2, $week['body']['total']);

        // Reviews can be listed by author
        $reviews = $this->client->call(Client::METHOD_GET, '/reviews', $this->projectHeaders(), [
            'queries' => [
                Query::equal('repositoryId', [$repositoryId])->toString(),
                Query::equal('providerPullRequestAuthor', ['alice'])->toString(),
            ],
        ]);
        $this->assertSame(2, $reviews['body']['total']);

        /**
         * Test for FAILURE
         */

        $invalid = $this->client->call(Client::METHOD_GET, '/reviews/authors', $this->projectHeaders(), [
            'range' => '1y',
        ]);
        $this->assertSame(400, $invalid['headers']['status-code']);

        // Another project sees none of them
        $authors = $this->client->call(Client::METHOD_GET, '/reviews/authors', $this->projectHeaders($this->getProject(true)['$id']), [
            'repositoryId' => $repositoryId,
        ]);
        $this->assertSame(200, $authors['headers']['status-code']);
        $this->assertSame(0, $authors['body']['total']);

        $this->client->call(Client::METHOD_DELETE, '/reviews/repositories/' . $repositoryId, $this->projectHeaders());
    }

    public function testListCategories(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->setupInstallation();

        $repository = $this->client->call(Client::METHOD_POST, '/reviews/repositories', $this->projectHeaders(), [
            'installationId' => $installationId,
            'providerRepositoryId' => $this->providerRepositoryId,
        ]);
        $this->assertSame(201, $repository['headers']['status-code']);
        $repositoryId = $repository['body']['$id'];

        $pull = \random_int(1000, 9_999_999);
        $other = $pull + 1;

        $this->sendPullRequestEvent('opened', $pull, \str_repeat('a', 40));
        $reviewId = $this->listReviews($pull)['body']['reviews'][0]['$id'];
        $this->completeReview($projectId, $reviewId, 'request_changes', 'C', [
            ['severity' => 'major', 'status' => 'posted', 'category' => 'bug'],
            ['severity' => 'minor', 'status' => 'posted', 'category' => 'style'],
            ['severity' => 'minor', 'status' => 'posted', 'category' => 'style'],
            ['severity' => 'info', 'status' => 'posted', 'category' => 'docs'],
            ['severity' => 'minor', 'status' => 'dropped', 'category' => 'style'],
            ['severity' => 'major', 'status' => 'posted', 'category' => 'security'],
        ]);

        $this->sendPullRequestEvent('opened', $other, \str_repeat('c', 40));
        $otherReviewId = $this->listReviews($other)['body']['reviews'][0]['$id'];
        $this->completeReview($projectId, $otherReviewId, 'approve', 'A', [
            ['severity' => 'minor', 'status' => 'posted', 'category' => 'style'],
        ]);

        $findingsPath = '/reviews/repositories/' . $repositoryId . '/pulls/' . $pull . '/findings';
        $ids = \array_column($this->callService(Client::METHOD_GET, $findingsPath, ['projectId' => $projectId])['body']['findings'], 'id');

        $settle = fn (string $id, string $status) => $this->callService(Client::METHOD_PATCH, $findingsPath . '/status', [
            'projectId' => $projectId,
            'findingIds' => [$id],
            'status' => $status,
            'reason' => 'Settled.',
            'from' => 'posted',
        ]);
        $this->assertSame(204, $settle($ids[0], 'resolved')['headers']['status-code']);
        $this->assertSame(204, $settle($ids[1], 'dismissed')['headers']['status-code']);
        $this->assertSame(204, $settle($ids[5], 'dismissed')['headers']['status-code']);

        /**
         * Test for SUCCESS
         */

        // Dropped findings were never posted, so they are not counted.
        $categories = $this->client->call(Client::METHOD_GET, '/reviews/categories', $this->projectHeaders(), [
            'repositoryId' => $repositoryId,
        ]);
        $this->assertSame(200, $categories['headers']['status-code']);
        $this->assertSame(4, $categories['body']['total']);
        // Most dismissed first, then most findings
        $this->assertSame(['style', 'security'], \array_slice(\array_column($categories['body']['categories'], 'category'), 0, 2));
        $byCategory = \array_column($categories['body']['categories'], null, 'category');
        $this->assertSame(['category' => 'style', 'findings' => 3, 'resolved' => 0, 'dismissed' => 1], $byCategory['style']);
        $this->assertSame(['category' => 'bug', 'findings' => 1, 'resolved' => 1, 'dismissed' => 0], $byCategory['bug']);
        $this->assertSame(['category' => 'security', 'findings' => 1, 'resolved' => 0, 'dismissed' => 1], $byCategory['security']);
        $this->assertSame(['category' => 'docs', 'findings' => 1, 'resolved' => 0, 'dismissed' => 0], $byCategory['docs']);

        $week = $this->client->call(Client::METHOD_GET, '/reviews/categories', $this->projectHeaders(), [
            'repositoryId' => $repositoryId,
            'range' => '7d',
        ]);
        $this->assertSame(200, $week['headers']['status-code']);
        $this->assertSame(4, $week['body']['total']);

        /**
         * Test for FAILURE
         */

        $invalidRange = $this->client->call(Client::METHOD_GET, '/reviews/categories', $this->projectHeaders(), [
            'range' => '1y',
        ]);
        $this->assertSame(400, $invalidRange['headers']['status-code']);

        // Another project sees none of them
        $categories = $this->client->call(Client::METHOD_GET, '/reviews/categories', $this->projectHeaders($this->getProject(true)['$id']), [
            'repositoryId' => $repositoryId,
        ]);
        $this->assertSame(200, $categories['headers']['status-code']);
        $this->assertSame(0, $categories['body']['total']);

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

    private function projectHeaders(?string $projectId = null): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId ?? $this->getProject()['$id'],
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

    /**
     * Starts and completes a review the way the review service does.
     *
     * @param array<array{severity: string, status: string, category?: string}> $findings
     */
    private function completeReview(string $projectId, string $reviewId, string $verdict, string $tier, array $findings = []): void
    {
        $started = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'processing',
            'headSha' => \str_repeat('e', 40),
            'baseSha' => \str_repeat('f', 40),
        ]);
        $this->assertSame(204, $started['headers']['status-code']);

        $completed = $this->callService(Client::METHOD_PATCH, '/reviews/' . $reviewId . '/status', [
            'projectId' => $projectId,
            'status' => 'completed',
            'verdict' => $verdict,
            'tier' => $tier,
            'findings' => \array_map(fn (array $finding, int $index) => [
                'path' => 'src/index.ts',
                'startLine' => $index + 1,
                'endLine' => $index + 1,
                'severity' => $finding['severity'],
                'category' => $finding['category'] ?? 'bug',
                'title' => 'Finding ' . $index,
                'body' => 'Something is off.',
                'suggestion' => null,
                'status' => $finding['status'],
                'dropReason' => $finding['status'] === 'dropped' ? 'Not confirmed by verification.' : null,
                'providerCommentId' => null,
            ], $findings, \array_keys($findings)),
        ]);
        $this->assertSame(204, $completed['headers']['status-code']);
    }

    private function listReviews(int $pullRequest, array $queries = [], ?string $projectId = null): array
    {
        $reviews = $this->client->call(Client::METHOD_GET, '/reviews', $this->projectHeaders($projectId), [
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

    /**
     * @param array<string> $providerRepositoryIds
     */
    private function sendInstallationRepositoriesEvent(string $action, array $providerRepositoryIds): array
    {
        $repositories = \array_map(fn (string $id) => ['id' => (int) $id, 'name' => 'repository-' . $id], $providerRepositoryIds);

        $payload = [
            'action' => $action,
            'installation' => ['id' => (int) $this->providerInstallationId, 'account' => ['login' => 'appwrite-test']],
            'repository_selection' => 'selected',
            'repositories_added' => $action === 'added' ? $repositories : [],
            'repositories_removed' => $action === 'removed' ? $repositories : [],
        ];

        return $this->client->call(Client::METHOD_POST, '/vcs/github/events', [
            'content-type' => 'application/json',
            'x-github-event' => 'installation_repositories',
            'x-hub-signature-256' => 'sha256=' . \hash_hmac(
                'sha256',
                \json_encode($payload, JSON_THROW_ON_ERROR),
                System::getEnv('_APP_VCS_GITHUB_WEBHOOK_SECRET', ''),
            ),
        ], $payload);
    }

    /**
     * @param array<string> $providerRepositoryIds
     */
    private function listRepositories(array $providerRepositoryIds, ?string $projectId = null): array
    {
        $repositories = $this->client->call(Client::METHOD_GET, '/reviews/repositories', $this->projectHeaders($projectId), [
            'queries' => [Query::equal('providerRepositoryId', $providerRepositoryIds)->toString()],
        ]);
        $this->assertSame(200, $repositories['headers']['status-code']);

        return $repositories;
    }

    private function sendPullRequestEvent(string $action, int $pullRequest, string $head, string $author = 'appwrite-test'): array
    {
        $payload = [
            'action' => $action,
            'number' => $pullRequest,
            'pull_request' => [
                'head' => ['ref' => 'review-e2e', 'sha' => $head, 'user' => ['login' => 'appwrite-test']],
                'base' => ['ref' => 'main', 'user' => ['login' => 'appwrite-test']],
                'user' => ['login' => $author, 'avatar_url' => ''],
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
