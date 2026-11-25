<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub;

use Appwrite\Event\Message\Review as ReviewMessage;
use Appwrite\Event\Message\ReviewChat as ReviewChatMessage;
use Appwrite\Event\Publisher\Review as ReviewPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

/**
 * Code review for a provider's webhook handler: queues reviews of pull requests and answers to
 * comments for the review service (appwrite/hansi). Shared by every provider reviews support,
 * like Deployment is, so each handler only parses its own deliveries.
 */
trait Reviews
{
    /**
     * The handle comments mention the reviewer by, without the @. Empty turns mentions off.
     */
    abstract protected function getReviewerHandle(): string;

    /**
     * The login the reviewer's own comments are authored by, so it never answers itself.
     */
    abstract protected function getReviewerLogin(): string;

    /**
     * The comment author's standing in the repository, as GitHub's author association names it:
     * OWNER, MEMBER or COLLABORATOR may queue work, anything else may not.
     */
    abstract protected function getCommentAuthorAssociation(array $parsedPayload, VcsFactory $vcsFactory): string;

    /**
     * Queues a review of the pull request for each project that has code review on for the
     * repository, as hansi.codes does: reviews still queued for the pull request are superseded
     * by the new one, so a burst of pushes runs one review, of the head it finds when it runs.
     */
    protected function queueReviews(
        string $provider,
        array $parsedPayload,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        ReviewPublisher $publisherForReviews,
        array $platform,
    ): void {
        // Drafts are queued too: the service decides from the repository's own config.
        $trigger = match ($parsedPayload['action'] ?? '') {
            'opened', 'reopened', 'ready_for_review' => 'opened',
            'synchronize' => 'synchronize',
            default => null,
        };
        if ($trigger === null) {
            return;
        }

        $providerRepositoryId = $parsedPayload['repositoryId'] ?? '';
        $pullNumber = (int) ($parsedPayload['pullRequestNumber'] ?? 0);
        $providerCommitHash = $parsedPayload['commitHash'] ?? '';

        $repositories = $this->findReviewRepositories($provider, $providerRepositoryId, \strval($parsedPayload['installationId'] ?? ''), $dbForPlatform, $authorization);

        foreach ($repositories as $repository) {
            try {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
                if ($project->isEmpty() || !$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                $this->queueReview(
                    $provider,
                    $parsedPayload,
                    $project,
                    $repository,
                    $getProjectDB($project),
                    $dbForPlatform,
                    $authorization,
                    $publisherForReviews,
                    $platform,
                    $pullNumber,
                    $providerCommitHash,
                    ['kind' => $trigger],
                    Id::unique(),
                );
            } catch (\Throwable $error) {
                Console::warning("Failed to queue review for repository '{$repository->getId()}': " . $error->getMessage());
            }
        }
    }

    /**
     * Queues work for a comment on a pull request, for each project that has code review on for
     * the repository, the way hansi.codes does: a review for "@app review", an answer for any
     * other mention. A comment without a mention is queued when it may be talking to the
     * reviewer: a reply in a review thread, or a conversation comment on a pull request with
     * findings that were listed in a review body; the service answers only if it is. Edits and
     * deletions queue nothing, and each comment queues at most once, however often the provider
     * delivers it.
     *
     * Comment ids are strings: GitHub's are numbers, Origin's are not. A review thread is named
     * by its first comment on GitHub and by its own id on Origin, where each comment carries it.
     */
    protected function queueComment(
        string $provider,
        array $parsedPayload,
        VcsFactory $vcsFactory,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $getProjectDB,
        ReviewPublisher $publisherForReviews,
        array $platform,
    ): void {
        $commentId = $this->getProviderId($parsedPayload['commentId'] ?? '');
        $commentKind = $parsedPayload['commentKind'] ?? '';
        $replyToId = $this->getProviderId($parsedPayload['commentReplyToId'] ?? '');
        $threadId = $this->getProviderId($parsedPayload['commentThreadId'] ?? '');
        $pullNumber = (int) ($parsedPayload['pullRequestNumber'] ?? 0);
        if (($parsedPayload['action'] ?? '') !== 'created' || $commentId === '' || $pullNumber <= 0) {
            return;
        }

        $handle = $this->getReviewerHandle();
        $author = \strval($parsedPayload['commentAuthor'] ?? '');
        $authorType = \strval($parsedPayload['commentAuthorType'] ?? '');
        $repositoryName = ($parsedPayload['owner'] ?? '') . '/' . ($parsedPayload['repositoryName'] ?? '');
        $installationId = \strval($parsedPayload['installationId'] ?? '');
        $providerRepositoryId = \strval($parsedPayload['repositoryId'] ?? '');
        // Each comment that is not answered says why: a dropped reply otherwise leaves no trace.
        $subject = "{$commentKind} comment {$commentId} on pull request {$pullNumber} of repository '{$repositoryName}' by '{$author}' ({$authorType})";

        $repositories = $this->findReviewRepositories($provider, $providerRepositoryId, $installationId, $dbForPlatform, $authorization);
        if (empty($repositories)) {
            Console::info("Ignored {$subject}: no project reviews the repository through installation '{$installationId}'");
            return;
        }

        // Every answer is a model run the project pays for: trusted humans and other apps may ask, never the reviewer itself.
        // Checked once code review is known to be on, as a provider may have to be asked who the author is.
        $association = $this->getCommentAuthorAssociation($parsedPayload, $vcsFactory);
        if (!$this->canTriggerFromComment($author, $authorType, $association, $this->getReviewerLogin())) {
            // Other apps may ask, so the only bot refused here is the reviewer itself, whose own
            // comments arrive on every review. Everyone else refused gets a line.
            if ($authorType !== 'Bot' && $authorType !== 'App') {
                Console::info("Ignored {$subject}: the author is not an owner, member or collaborator ({$association})");
            }
            return;
        }

        $intent = $this->getMentionIntent($parsedPayload['commentBody'] ?? '', $handle);
        $isReviewThread = $commentKind === 'review';

        foreach ($repositories as $repository) {
            try {
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $repository->getAttribute('projectId', '')));
                if ($project->isEmpty()) {
                    Console::info("Ignored {$subject} for repository '{$repository->getId()}': its project is missing");
                    continue;
                }
                if (!$this->isProjectInCurrentRegion($project)) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);

                if ($intent === 'review') {
                    // The service reviews whatever the head is when it runs, so no head is read here.
                    $this->queueReview(
                        $provider,
                        $parsedPayload,
                        $project,
                        $repository,
                        $dbForProject,
                        $dbForPlatform,
                        $authorization,
                        $publisherForReviews,
                        $platform,
                        $pullNumber,
                        '',
                        ['kind' => 'mention', 'comment' => ['id' => $this->toPayloadId($commentId), 'kind' => $commentKind]],
                        // One review per comment, however often the provider delivers it.
                        $this->getClaimId('mention-', $commentId),
                    );
                    continue;
                }

                $unmentioned = $intent === null;
                if ($unmentioned && !($isReviewThread ? $replyToId !== '' : $this->hasOpenBodyFindings($repository, $pullNumber, $dbForProject, $authorization))) {
                    continue;
                }

                // An answer is a model run too, so it is refused like a review. Nothing is recorded:
                // a chat has no status for the project to see.
                try {
                    $this->beforeQueueReview($project, $repository, $dbForPlatform, $authorization);
                } catch (Exception $error) {
                    Console::warning("Skipped {$subject} for repository '{$repository->getId()}': " . $error->getMessage());
                    continue;
                }

                // The row's id is the claim: a redelivery of the comment fails to create it again.
                try {
                    $chat = $authorization->skip(fn () => $dbForProject->createDocument('reviewChats', new Document([
                        '$id' => $this->getClaimId('chat-', $commentId),
                        'repositoryId' => $repository->getId(),
                        'repositoryInternalId' => $repository->getSequence(),
                        'providerPullRequestId' => (string) $pullNumber,
                        'providerCommentId' => $commentId,
                        'kind' => $commentKind,
                        'author' => $parsedPayload['commentAuthor'] ?? '',
                    ])));
                } catch (DuplicateException) {
                    Console::info("Ignored {$subject} for repository '{$repository->getId()}': it is already queued");
                    continue;
                }

                $comment = [
                    'id' => $this->toPayloadId($commentId),
                    'kind' => $commentKind,
                    'author' => $parsedPayload['commentAuthor'] ?? '',
                    'authorAssociation' => $association,
                    'url' => $parsedPayload['commentUrl'] ?? '',
                ];
                // A review thread's replies attach to its first comment, which this one is when it replies to nothing,
                // or, where the provider names threads, to the thread.
                if ($isReviewThread) {
                    $comment['rootCommentId'] = $this->toPayloadId($threadId !== '' ? $threadId : ($replyToId !== '' ? $replyToId : $commentId));
                }

                try {
                    $queued = $publisherForReviews->enqueue(new ReviewChatMessage(
                        repository: $this->getReviewRepository($provider, $parsedPayload, $project, $repository, $platform),
                        pullNumber: $pullNumber,
                        comment: $comment,
                        unmentioned: $unmentioned,
                    ));

                    if ($queued === false) {
                        throw new \Exception('The broker rejected the chat message.');
                    }

                    Console::info("Queued {$subject} for repository '{$repository->getId()}'");
                } catch (\Throwable $error) {
                    // Released, so a redelivery of the comment can queue it again.
                    $authorization->skip(fn () => $dbForProject->deleteDocument('reviewChats', $chat->getId()));

                    throw $error;
                }
            } catch (\Throwable $error) {
                Console::warning("Failed to queue {$subject} for repository '{$repository->getId()}': " . $error->getMessage());
            }
        }
    }

    /**
     * Creates a review and publishes it. Reviews still queued for the pull request are
     * superseded first; one that is already running is left alone, and the service skips the new
     * one when it finds the same head reviewed.
     *
     * @param array{kind: string, comment?: array{id: int|string, kind: string}} $trigger
     */
    private function queueReview(
        string $provider,
        array $parsedPayload,
        Document $project,
        Document $repository,
        Database $dbForProject,
        Database $dbForPlatform,
        Authorization $authorization,
        ReviewPublisher $publisherForReviews,
        array $platform,
        int $pullNumber,
        string $headSha,
        array $trigger,
        string $reviewId,
    ): void {
        $review = new Document([
            '$id' => $reviewId,
            'repositoryId' => $repository->getId(),
            'repositoryInternalId' => $repository->getSequence(),
            'providerRepositoryId' => $parsedPayload['repositoryId'] ?? '',
            'providerRepositoryOwner' => $parsedPayload['owner'] ?? '',
            'providerRepositoryName' => $parsedPayload['repositoryName'] ?? '',
            'providerPullRequestId' => (string) $pullNumber,
            'providerPullRequestAuthor' => $parsedPayload['pullRequestAuthor'] ?? '',
            'providerCommitHash' => $headSha,
            'trigger' => $trigger['kind'],
            'providerCommentId' => isset($trigger['comment']) ? (string) $trigger['comment']['id'] : '',
            'status' => 'queued',
            // Kept on the review, so it runs and is billed at the strength it was queued at.
            'strength' => $repository->getAttribute('strength', 'normal'),
        ]);

        // A redelivered comment that already queued its review changes nothing, not even the
        // reviews queued after it.
        $existing = $authorization->skip(fn () => $dbForProject->getDocument('reviews', $reviewId));
        if (!$existing->isEmpty() && $existing->getAttribute('status') !== 'failed') {
            return;
        }

        // Checked before anything is written, so a refusal leaves the pull request's queued reviews
        // alone and an error in the check leaves no review behind. A refused review is recorded as
        // failed, with the reason the project's users see.
        try {
            $this->beforeQueueReview($project, $repository, $dbForPlatform, $authorization);
        } catch (Exception $error) {
            $review
                ->setAttribute('status', 'failed')
                ->setAttribute('reason', $error->getMessage())
                ->setAttribute('completedAt', DateTime::now());

            try {
                $authorization->skip(fn () => $dbForProject->createDocument('reviews', $review));
            } catch (DuplicateException) {
                // A redelivered comment whose review failed before now shows why it is refused.
                $authorization->skip(fn () => $dbForProject->updateDocument('reviews', $reviewId, new Document([
                    'reason' => $error->getMessage(),
                    'completedAt' => DateTime::now(),
                ])));
            }

            return;
        }

        // Older queued reviews of the pull request are superseded before this one is created, as
        // hansi.codes does: two overlapping deliveries then both stay queued, and the worker skips
        // the one that resolves to an already reviewed head, instead of superseding each other.
        $authorization->skip(fn () => $dbForProject->updateDocuments('reviews', new Document([
            'status' => 'superseded',
        ]), [
            Query::equal('repositoryInternalId', [$repository->getSequence()]),
            Query::equal('providerPullRequestId', [(string) $pullNumber]),
            Query::equal('status', ['queued']),
            Query::notEqual('$id', $reviewId),
        ]));

        try {
            $review = $authorization->skip(fn () => $dbForProject->createDocument('reviews', $review));
        } catch (DuplicateException) {
            // A redelivered comment queues again only if its first attempt failed to. The row lock
            // lets one of two concurrent redeliveries take it back from failed.
            $review = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $reviewId): ?Document {
                $review = $dbForProject->getDocument('reviews', $reviewId, forUpdate: true);
                if ($review->getAttribute('status') !== 'failed') {
                    return null;
                }

                return $dbForProject->updateDocument('reviews', $review->getId(), new Document([
                    'status' => 'queued',
                    'reason' => '',
                ]));
            }));

            if ($review === null) {
                return;
            }
        }

        try {
            $queued = $publisherForReviews->enqueue(new ReviewMessage(
                reviewId: $review->getId(),
                repository: $this->getReviewRepository($provider, $parsedPayload, $project, $repository, $platform),
                pullNumber: $pullNumber,
                headSha: $headSha,
                trigger: $trigger,
                strength: $review->getAttribute('strength', 'normal'),
            ));

            if ($queued === false) {
                throw new \Exception('The broker rejected the review message.');
            }
        } catch (\Throwable $error) {
            $authorization->skip(fn () => $dbForProject->updateDocument('reviews', $review->getId(), new Document([
                'status' => 'failed',
                'reason' => 'The review could not be queued.',
            ])));

            throw $error;
        }
    }

    /**
     * Whether the project may run a review or answer a comment, checked before either is queued.
     * Self-hosted has no plans, so everything runs; Cloud refuses with its billing limits.
     *
     * @throws Exception when the project may not, with the reason shown to its users
     */
    protected function beforeQueueReview(Document $project, Document $repository, Database $dbForPlatform, Authorization $authorization): void
    {
    }

    /**
     * Whether a pull request has an open finding that was listed in a review body rather than
     * posted inline. Such a finding has no thread, so a conversation comment may answer it.
     */
    private function hasOpenBodyFindings(Document $repository, int $pullNumber, Database $dbForProject, Authorization $authorization): bool
    {
        $reviews = $authorization->skip(fn () => $dbForProject->find('reviews', [
            Query::equal('repositoryInternalId', [$repository->getSequence()]),
            Query::equal('providerPullRequestId', [(string) $pullNumber]),
            Query::equal('status', ['completed']),
            Query::limit(APP_LIMIT_REVIEWS_PULL),
        ]));
        if (empty($reviews)) {
            return false;
        }

        $finding = $authorization->skip(fn () => $dbForProject->findOne('reviewFindings', [
            Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $reviews)),
            Query::equal('status', ['posted']),
            Query::equal('providerCommentId', ['']),
        ]));

        return !$finding->isEmpty();
    }

    /**
     * Whether a comment may queue work. Humans need an owner, member or collaborator association;
     * other apps and bots may ask too (an agent answering a finding), but never the reviewer
     * itself, so it does not answer itself in a loop. $self is the login the reviewer comments as.
     */
    private function canTriggerFromComment(string $login, string $type, string $association, string $self): bool
    {
        if ($type === 'Bot' || $type === 'App') {
            $name = \str_ends_with(\strtolower($login), '[bot]') ? \substr($login, 0, -\strlen('[bot]')) : $login;

            return $self !== '' && \strtolower($name) !== \strtolower($self);
        }

        return \in_array($association, ['OWNER', 'MEMBER', 'COLLABORATOR'], true);
    }

    /**
     * What a comment asks of the reviewer: 'review' for "@app review" or "@app full review",
     * 'chat' for any other mention, or null when it does not mention the app. Mentions in code
     * and in quoted lines do not count, and a longer handle such as "@app-dev" is not a mention.
     */
    private function getMentionIntent(string $body, string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        $text = \strtolower($this->stripQuotedText($body));
        $needle = '@' . \strtolower($slug);
        $intent = null;
        $offset = 0;

        while (($position = \strpos($text, $needle, $offset)) !== false) {
            $offset = $position + \strlen($needle);
            $next = $text[$offset] ?? '';
            if ($next !== '' && (\ctype_alnum($next) || $next === '_' || $next === '-')) {
                continue;
            }

            $intent = 'chat';
            $rest = \substr($text, $offset);
            $words = \ltrim($rest);
            if ($words === $rest) {
                continue;
            }
            if (\str_starts_with($words, 'full') && \ltrim(\substr($words, 4)) !== \substr($words, 4)) {
                $words = \ltrim(\substr($words, 4));
            }
            $after = $words[\strlen('review')] ?? '';
            if (\str_starts_with($words, 'review') && ($after === '' || !(\ctype_alnum($after) || $after === '_'))) {
                return 'review';
            }
        }

        return $intent;
    }

    /**
     * A comment without its fenced code blocks, inline code spans and quoted lines.
     */
    private function stripQuotedText(string $body): string
    {
        $text = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length;) {
            if (\substr($body, $i, 3) === '```') {
                $close = \strpos($body, '```', $i + 3);
                if ($close !== false) {
                    $i = $close + 3;
                    continue;
                }
            } elseif ($body[$i] === '`') {
                $close = \strpos($body, '`', $i + 1);
                if ($close !== false && !\str_contains(\substr($body, $i + 1, $close - $i - 1), "\n")) {
                    $i = $close + 1;
                    continue;
                }
            }
            $text .= $body[$i];
            $i++;
        }

        $lines = \array_filter(\explode("\n", $text), fn (string $line) => !\str_starts_with(\ltrim($line), '>'));

        return \implode("\n", $lines);
    }

    /**
     * The repository as the review service's jobs name it.
     *
     * @return array{provider: string, projectId: string, projectInternalId: string, repositoryId: string, installationId: string, providerInstallationId: string, providerRepositoryId: string, owner: string, name: string, consoleUrl?: string, imagesUrl?: string}
     */
    private function getReviewRepository(string $provider, array $parsedPayload, Document $project, Document $repository, array $platform): array
    {
        $reviewRepository = [
            'provider' => $provider,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'repositoryId' => $repository->getId(),
            'installationId' => $repository->getAttribute('installationId', ''),
            'providerInstallationId' => $parsedPayload['installationId'] ?? '',
            'providerRepositoryId' => $parsedPayload['repositoryId'] ?? '',
            'owner' => $parsedPayload['owner'] ?? '',
            'name' => $parsedPayload['repositoryName'] ?? '',
        ];

        // For links to the review, from its check run and the summary comment.
        $consoleUrl = $platform['consoleUrl'] ?? '';
        if ($consoleUrl !== '') {
            $reviewRepository['consoleUrl'] = $consoleUrl;
        }

        // For the status images in the summary comment, served from here as for Sites and Functions comments.
        $hostname = $platform['consoleHostname'] ?? '';
        if ($hostname !== '') {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $reviewRepository['imagesUrl'] = $protocol . '://' . $hostname;
        }

        return $reviewRepository;
    }

    /**
     * Repositories of every project that has code review on for this provider repository,
     * connected through the delivery's installation. Every match is returned, because each row is
     * a separate project that asked for review and none may be skipped.
     *
     * @return array<Document>
     */
    private function findReviewRepositories(string $provider, string $providerRepositoryId, string $providerInstallationId, Database $dbForPlatform, Authorization $authorization): array
    {
        if ($providerRepositoryId === '' || $providerInstallationId === '') {
            return [];
        }

        $found = [];
        $cursor = null;

        do {
            $page = $authorization->skip(fn () => $dbForPlatform->find('repositories', [
                Query::equal('providerRepositoryId', [$providerRepositoryId]),
                Query::equal('resourceType', [RESOURCE_TYPE_REVIEWS]),
                Query::limit(100),
                ...($cursor === null ? [] : [Query::cursorAfter($cursor)]),
            ]));

            $installationIds = \array_values(\array_unique(\array_filter(\array_map(fn (Document $repository) => $repository->getAttribute('installationId', ''), $page))));
            $installations = empty($installationIds) ? [] : $authorization->skip(fn () => $dbForPlatform->find('installations', [
                Query::equal('$id', $installationIds),
                Query::equal('provider', [$provider]),
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::limit(\count($installationIds)),
            ]));
            $installationIds = \array_map(fn (Document $installation) => $installation->getId(), $installations);

            foreach ($page as $repository) {
                if (\in_array($repository->getAttribute('installationId', ''), $installationIds, true)) {
                    $found[] = $repository;
                }
            }

            $cursor = \count($page) === 100 ? $page[\array_key_last($page)] : null;
        } while ($cursor !== null);

        return $found;
    }

    /**
     * A provider id as a string, empty when there is none: GitHub reports missing ids as 0.
     */
    private function getProviderId(mixed $id): string
    {
        $id = \is_int($id) || \is_string($id) ? \strval($id) : '';

        return $id === '0' ? '' : $id;
    }

    /**
     * An id as the review service's jobs carry it: a number where the provider's ids are
     * numbers, as GitHub's are, and the string otherwise.
     */
    private function toPayloadId(string $id): int|string
    {
        return \ctype_digit($id) ? (int) $id : $id;
    }

    /**
     * The id of the row that claims a comment, so each comment is handled once. An id too long to
     * fit after the prefix, as Origin's are, is hashed.
     */
    private function getClaimId(string $prefix, string $commentId): string
    {
        $id = $prefix . $commentId;
        if (\strlen($id) <= 36) {
            return $id;
        }

        return $prefix . \substr(\hash('sha256', $commentId), 0, 36 - \strlen($prefix));
    }

}
