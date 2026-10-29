<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Reviews\Status;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Reviews\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * The review service moves a review along: it starts on a head, then completes, is skipped or
 * fails. A review that has already ended is not reopened, so a redelivered job that reports
 * again gets a conflict it can treat as done.
 */
class Update extends Base
{
    use HTTP;

    private const array SEVERITIES = ['info', 'minor', 'major', 'critical'];

    public static function getName()
    {
        return 'updateReviewStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/reviews/:reviewId/status')
            ->desc('Update review status')
            ->groups(['api'])
            ->label('scope', 'public')
            // Keyed on the caller's address: the limit runs before the secret is checked, so any
            // request param would let an unauthenticated caller pick a fresh bucket. {url} is the
            // route pattern, so the review service gets one bucket per route for every project.
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 10)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->param('reviewId', '', new UID(), 'Review ID.')
            ->param('projectId', '', new UID(), 'Project ID.')
            ->param('status', '', new WhiteList(['processing', 'completed', 'skipped', 'failed'], true), 'Review status.')
            ->param('headSha', '', new Text(64), 'Head commit the review works on. Required when processing; when skipped, the head that was reviewed already.', true)
            ->param('baseSha', '', new Text(64), 'Base commit the head is compared with. Required when processing.', true)
            ->param('verdict', '', new WhiteList(['approve', 'request_changes', 'comment'], true), 'Review verdict. Required when completed.', true)
            ->param('tier', '', new WhiteList(['S', 'A', 'B', 'C', 'D', 'F'], true), 'Merge confidence tier. Required when completed.', true)
            ->param('tierReason', '', new Text(2048, 0), 'Why the review has its tier.', true)
            ->param('summary', '', new Text(65535, 0), 'Review summary in Markdown.', true)
            ->param('walkthrough', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'What each changed file does, as path and change.', true)
            ->param('findings', [], new ArrayList(new Assoc(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Findings of the review, posted or dropped.', true)
            ->param('providerReviewId', 0, new Range(0, PHP_INT_MAX), 'GitHub review ID.', true)
            ->param('reason', '', new Text(2048, 0), 'Why the review was skipped or failed, shown to the project\'s users.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $reviewId,
        string $projectId,
        string $status,
        string $headSha,
        string $baseSha,
        string $verdict,
        string $tier,
        string $tierReason,
        string $summary,
        array $walkthrough,
        array $findings,
        int $providerReviewId,
        string $reason,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        callable $getProjectDB,
        Authorization $authorization,
    ): void {
        $this->authenticate($request);

        $project = $this->getProject($projectId, $dbForPlatform, $authorization);
        $dbForProject = $getProjectDB($project);
        $review = $this->getReview($reviewId, $dbForProject, $authorization);

        // processing may repeat: a job redelivered after a crash starts again.
        if (!\in_array($review->getAttribute('status'), ['queued', 'processing'], true)) {
            throw new Exception(Exception::REVIEW_STATUS_INVALID, "Review is already {$review->getAttribute('status')}.");
        }

        $update = match ($status) {
            'processing' => $this->start($headSha, $baseSha),
            'completed' => $this->complete($verdict, $tier, $tierReason, $summary, $walkthrough, $providerReviewId),
            default => new Document([
                'reason' => $reason,
                'completedAt' => DateTime::now(),
            ]),
        };
        // A skipped review names the head it found reviewed already.
        if ($status === 'skipped' && $headSha !== '') {
            $update->setAttribute('providerCommitHash', $headSha);
        }
        $update->setAttribute('status', $status);

        $documents = $status === 'completed' ? $this->prepareFindings($review, $findings) : [];

        // One transaction, so a failed status write leaves no findings behind for a retry to duplicate.
        $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $review, $update, $documents) {
            if (!empty($documents)) {
                $dbForProject->createDocuments('reviewFindings', $documents);
            }

            $dbForProject->updateDocument('reviews', $review->getId(), $update);
        }));

        $response->noContent();
    }

    private function start(string $headSha, string $baseSha): Document
    {
        if ($headSha === '' || $baseSha === '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "headSha" and "baseSha" are required when the status is processing.');
        }

        return new Document([
            'providerCommitHash' => $headSha,
            'providerBaseCommitHash' => $baseSha,
            'startedAt' => DateTime::now(),
        ]);
    }

    /**
     * @param array<mixed> $walkthrough
     */
    private function complete(string $verdict, string $tier, string $tierReason, string $summary, array $walkthrough, int $providerReviewId): Document
    {
        if ($verdict === '' || $tier === '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "verdict" and "tier" are required when the status is completed.');
        }

        $entries = [];
        foreach ($walkthrough as $index => $entry) {
            $path = \is_array($entry) ? ($entry['path'] ?? null) : null;
            $change = \is_array($entry) ? ($entry['change'] ?? null) : null;
            if (!\is_string($path) || $path === '' || !\is_string($change)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Walkthrough entry {$index} is invalid: it needs path and change.");
            }
            $entries[] = ['path' => $path, 'change' => $change];
        }

        return new Document([
            'verdict' => $verdict,
            'tier' => $tier,
            'tierReason' => $tierReason,
            'summary' => $summary,
            'walkthrough' => $entries,
            'providerReviewId' => $providerReviewId === 0 ? '' : \strval($providerReviewId),
            'completedAt' => DateTime::now(),
        ]);
    }

    /**
     * @param array<array<string, mixed>> $findings
     * @return array<Document>
     */
    private function prepareFindings(Document $review, array $findings): array
    {
        $documents = [];

        foreach ($findings as $index => $finding) {
            $path = $finding['path'] ?? null;
            $startLine = $finding['startLine'] ?? null;
            $endLine = $finding['endLine'] ?? null;
            $severity = $finding['severity'] ?? null;
            $category = $finding['category'] ?? null;
            $title = $finding['title'] ?? null;
            $body = $finding['body'] ?? null;
            $providerCommentId = $finding['providerCommentId'] ?? null;
            $suggestion = $finding['suggestion'] ?? null;
            $status = $finding['status'] ?? 'posted';
            $dropReason = $finding['dropReason'] ?? null;

            $valid = \is_string($path) && $path !== '' && \strlen($path) <= 2048
                && \is_int($startLine) && $startLine > 0
                && \is_int($endLine) && $endLine >= $startLine
                && \in_array($severity, self::SEVERITIES, true)
                && \is_string($category) && $category !== '' && \strlen($category) <= 64
                && \is_string($title) && $title !== '' && \strlen($title) <= 1024
                && \is_string($body) && \strlen($body) <= 65535
                && ($suggestion === null || (\is_string($suggestion) && \strlen($suggestion) <= 65535))
                && \in_array($status, ['posted', 'dropped'], true)
                && ($dropReason === null || (\is_string($dropReason) && \strlen($dropReason) <= 2048))
                && ($providerCommentId === null || \is_int($providerCommentId));

            if (!$valid) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Finding {$index} is invalid: it needs path, startLine, endLine (from startLine), severity (" . \implode(', ', self::SEVERITIES) . '), category, title and body, with an optional suggestion, status (posted or dropped), dropReason and numeric providerCommentId.');
            }

            $documents[] = new Document([
                '$id' => Id::unique(),
                'reviewId' => $review->getId(),
                'reviewInternalId' => $review->getSequence(),
                'path' => $path,
                'startLine' => $startLine,
                'endLine' => $endLine,
                'severity' => $severity,
                'category' => $category,
                'title' => $title,
                'body' => $body,
                'suggestion' => $suggestion ?? '',
                'status' => $status,
                'dropReason' => $dropReason ?? '',
                'providerCommentId' => $providerCommentId === null ? '' : \strval($providerCommentId),
            ]);
        }

        return $documents;
    }
}
