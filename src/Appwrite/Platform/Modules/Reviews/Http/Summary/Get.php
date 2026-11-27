<?php

namespace Appwrite\Platform\Modules\Reviews\Http\Summary;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * How a project's pull requests fared in review over a range and over the same length before it,
 * what the reviews of the range concluded day by day, and how each repository fared. A range is
 * whole UTC days, today included, so the days add up to the range. Each period is computed from
 * its completed reviews, newest first, up to APP_LIMIT_REVIEW_AUTHORS of them.
 */
class Get extends Action
{
    use HTTP;

    private const array RANGES = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    private const int DAY = 86400;

    private const int PAGE = 1000;

    /** Reviews whose findings are read in one query: at most 100 findings each. */
    private const int FINDINGS_CHUNK = 50;

    private const array SEVERITIES = ['critical', 'major', 'minor', 'info'];

    /** Day counters by the verdict they count. */
    private const array VERDICTS = [
        'approve' => 'approved',
        'request_changes' => 'changesRequested',
        'comment' => 'commented',
    ];

    public static function getName()
    {
        return 'getSummary';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/reviews/summary')
            ->desc('Get review summary')
            ->groups(['api', 'reviews'])
            ->label('scope', 'reviews.read')
            ->label('sdk', new Method(
                namespace: 'reviews',
                group: 'summary',
                name: 'getSummary',
                description: <<<EOT
                Get how the project's pull requests fared in review over a range and over the same length of time before it: pull requests and reviews, approvals, findings by severity, findings fixed and dismissed, and the median time a review took. The response also counts each day of the range, reviews by verdict and findings by severity, and sums the range up for each repository.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_REVIEW_SUMMARY,
                    )
                ],
                // Hidden until code review is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('range', '30d', new WhiteList(\array_keys(self::RANGES), true), 'Date range of the reviews: whole days, today included.', true)
            ->param('repositoryId', '', new UID(), 'Count only the reviews of this repository.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $range,
        string $repositoryId,
        Response $response,
        Database $dbForProject,
    ): void {
        $days = self::RANGES[$range];

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $start = (clone $now)->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        $previousStart = (clone $start)->modify('-' . $days . ' days');

        $filters = [Query::equal('status', ['completed'])];
        if ($repositoryId !== '') {
            $filters[] = Query::equal('repositoryId', [$repositoryId]);
        }

        $current = $this->listReviews([
            ...$filters,
            Query::greaterThanEqual('$createdAt', DateTime::format($start)),
        ], $dbForProject);
        $previous = $this->listReviews([
            ...$filters,
            Query::greaterThanEqual('$createdAt', DateTime::format($previousStart)),
            Query::lessThan('$createdAt', DateTime::format($start)),
        ], $dbForProject);
        $findings = $this->countFindings([...$current, ...$previous], $dbForProject);

        $response->dynamic(new Document([
            'periods' => [
                $this->summarize($current, $findings, $start, $now),
                $this->summarize($previous, $findings, $previousStart, $start),
            ],
            'days' => $this->countDays($current, $findings, $start, $days),
            'repositories' => $this->summarizeRepositories($current, $findings),
        ]), Response::MODEL_REVIEW_SUMMARY);
    }

    /**
     * The totals of one period. Pull requests count once each, by their reviews of the period:
     * approved when the latest approved, approved first when the first did.
     *
     * @param array<Document> $reviews Newest first.
     * @param array<int|string, array<string, int>> $findings
     */
    private function summarize(array $reviews, array $findings, \DateTime $start, \DateTime $end): Document
    {
        $period = [
            'start' => DateTime::format($start),
            'end' => DateTime::format($end),
            'pulls' => 0,
            'reviews' => \count($reviews),
            'approved' => 0,
            'approvedFirst' => 0,
            'findings' => 0,
            'critical' => 0,
            'major' => 0,
            'minor' => 0,
            'info' => 0,
            'resolved' => 0,
            'dismissed' => 0,
            'duration' => 0,
        ];

        // Pull requests by repository and number, each with its reviews oldest first.
        $pulls = [];
        $durations = [];
        foreach (\array_reverse($reviews) as $review) {
            $key = $review->getAttribute('repositoryInternalId') . ':' . $review->getAttribute('providerPullRequestId');
            $pulls[$key][] = $review;

            foreach ($findings[$review->getSequence()] ?? [] as $metric => $count) {
                $period[$metric] += $count;
            }

            $duration = $this->duration($review);
            if ($duration !== null) {
                $durations[] = $duration;
            }
        }

        foreach ($pulls as $pull) {
            $period['pulls']++;
            $period['approved'] += $pull[\count($pull) - 1]->getAttribute('verdict') === 'approve' ? 1 : 0;
            $period['approvedFirst'] += $pull[0]->getAttribute('verdict') === 'approve' ? 1 : 0;
        }

        $period['duration'] = $this->median($durations);

        return new Document($period);
    }

    /**
     * Each day of the range, oldest first: its completed reviews by verdict and what they found.
     *
     * @param array<Document> $reviews
     * @param array<int|string, array<string, int>> $findings
     * @return array<Document>
     */
    private function countDays(array $reviews, array $findings, \DateTime $start, int $days): array
    {
        $counts = [];
        for ($day = 0; $day < $days; $day++) {
            $counts[$day] = [
                'date' => DateTime::format((clone $start)->modify('+' . $day . ' days')),
                'reviews' => 0,
                'approved' => 0,
                'changesRequested' => 0,
                'commented' => 0,
                'findings' => 0,
                'critical' => 0,
                'major' => 0,
                'minor' => 0,
                'info' => 0,
                'resolved' => 0,
                'dismissed' => 0,
            ];
        }

        foreach ($reviews as $review) {
            $createdAt = $review->getCreatedAt();
            if (empty($createdAt)) {
                continue;
            }
            $day = \intdiv((new \DateTime($createdAt))->getTimestamp() - $start->getTimestamp(), self::DAY);
            if (!isset($counts[$day])) {
                continue;
            }

            $counts[$day]['reviews']++;
            $verdict = self::VERDICTS[$review->getAttribute('verdict', '')] ?? null;
            if ($verdict !== null) {
                $counts[$day][$verdict]++;
            }
            foreach ($findings[$review->getSequence()] ?? [] as $metric => $count) {
                $counts[$day][$metric] += $count;
            }
        }

        return \array_map(fn (array $count) => new Document($count), $counts);
    }

    /**
     * How each repository's pull requests fared over the range, the busiest first. Pull requests
     * count once each: approved when their latest review approved, approved first when the first
     * did.
     *
     * @param array<Document> $reviews Newest first.
     * @param array<int|string, array<string, int>> $findings
     * @return array<Document>
     */
    private function summarizeRepositories(array $reviews, array $findings): array
    {
        $repositories = [];
        $pulls = [];
        foreach (\array_reverse($reviews) as $review) {
            $repositoryId = $review->getAttribute('repositoryId', '');
            $repository = &$repositories[$repositoryId];
            $repository ??= [
                'repositoryId' => $repositoryId,
                'providerRepositoryOwner' => '',
                'providerRepositoryName' => '',
                'pulls' => 0,
                'reviews' => 0,
                'approved' => 0,
                'approvedFirst' => 0,
                'findings' => 0,
                'critical' => 0,
                'major' => 0,
                'resolved' => 0,
                'dismissed' => 0,
                'lastReviewAt' => '',
            ];

            $repository['reviews']++;
            // Reviews are walked oldest first, so the latest name wins if it was renamed.
            $repository['providerRepositoryOwner'] = $review->getAttribute('providerRepositoryOwner', '') ?: $repository['providerRepositoryOwner'];
            $repository['providerRepositoryName'] = $review->getAttribute('providerRepositoryName', '') ?: $repository['providerRepositoryName'];
            foreach ($findings[$review->getSequence()] ?? [] as $metric => $count) {
                // Minor and info findings are in the range's totals, not each repository's.
                if (isset($repository[$metric])) {
                    $repository[$metric] += $count;
                }
            }
            $repository['lastReviewAt'] = \max($repository['lastReviewAt'], $review->getCreatedAt() ?? '');
            unset($repository);

            $pulls[$repositoryId][$review->getAttribute('providerPullRequestId')][] = $review;
        }

        foreach ($pulls as $repositoryId => $byPull) {
            foreach ($byPull as $pull) {
                $repositories[$repositoryId]['pulls']++;
                $repositories[$repositoryId]['approved'] += $pull[\count($pull) - 1]->getAttribute('verdict') === 'approve' ? 1 : 0;
                $repositories[$repositoryId]['approvedFirst'] += $pull[0]->getAttribute('verdict') === 'approve' ? 1 : 0;
            }
        }

        $documents = \array_map(fn (array $repository) => new Document($repository), \array_values($repositories));
        \usort($documents, fn (Document $a, Document $b) => [$b->getAttribute('pulls'), $b->getAttribute('reviews')] <=> [$a->getAttribute('pulls'), $a->getAttribute('reviews')]);

        return $documents;
    }

    /**
     * Milliseconds from a review being queued to it completing; null when either is missing.
     */
    private function duration(Document $review): ?int
    {
        $createdAt = $review->getCreatedAt();
        $completedAt = $review->getAttribute('completedAt');
        if (empty($createdAt) || empty($completedAt)) {
            return null;
        }

        $queued = (float) (new \DateTime($createdAt))->format('U.v');
        $completed = (float) (new \DateTime($completedAt))->format('U.v');

        return $completed >= $queued ? (int) \round(($completed - $queued) * 1000) : null;
    }

    /**
     * @param array<int> $values
     */
    private function median(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        \sort($values);
        $middle = \intdiv(\count($values), 2);

        return \count($values) % 2 === 1
            ? $values[$middle]
            : (int) \round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Completed reviews matching the filters, newest first.
     *
     * @param array<Query> $filters
     * @return array<Document>
     */
    private function listReviews(array $filters, Database $dbForProject): array
    {
        $reviews = [];
        $cursor = null;

        while (\count($reviews) < APP_LIMIT_REVIEW_AUTHORS) {
            $page = $dbForProject->find('reviews', [
                ...$filters,
                Query::select(['$id', '$sequence', '$createdAt', 'repositoryId', 'repositoryInternalId', 'providerRepositoryOwner', 'providerRepositoryName', 'providerPullRequestId', 'verdict', 'completedAt']),
                Query::orderDesc('$createdAt'),
                Query::limit(\min(self::PAGE, APP_LIMIT_REVIEW_AUTHORS - \count($reviews))),
                ...($cursor === null ? [] : [Query::cursorAfter($cursor)]),
            ]);

            \array_push($reviews, ...$page);

            if (\count($page) < self::PAGE) {
                break;
            }

            $cursor = $page[\count($page) - 1];
        }

        return $reviews;
    }

    /**
     * What each review found, by review sequence. Dropped findings were never posted, so they
     * do not count.
     *
     * @param array<Document> $reviews
     * @return array<int|string, array<string, int>>
     */
    private function countFindings(array $reviews, Database $dbForProject): array
    {
        $counts = [];

        foreach (\array_chunk($reviews, self::FINDINGS_CHUNK) as $chunk) {
            $findings = $dbForProject->find('reviewFindings', [
                Query::equal('reviewInternalId', \array_map(fn (Document $review) => $review->getSequence(), $chunk)),
                Query::notEqual('status', 'dropped'),
                Query::select(['reviewInternalId', 'severity', 'status']),
                Query::limit(self::FINDINGS_CHUNK * APP_LIMIT_ARRAY_PARAMS_SIZE),
            ]);

            foreach ($findings as $finding) {
                $count = &$counts[$finding->getAttribute('reviewInternalId')];
                $count ??= ['findings' => 0, 'critical' => 0, 'major' => 0, 'minor' => 0, 'info' => 0, 'resolved' => 0, 'dismissed' => 0];

                $count['findings']++;
                $severity = $finding->getAttribute('severity');
                if (\in_array($severity, self::SEVERITIES, true)) {
                    $count[$severity]++;
                }
                $status = $finding->getAttribute('status');
                if ($status === 'resolved' || $status === 'dismissed') {
                    $count[$status]++;
                }
                unset($count);
            }
        }

        return $counts;
    }
}
